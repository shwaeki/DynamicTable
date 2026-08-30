/**
 * Laravel DynamicTable — core runtime.
 *
 * Deliberately dependency-free and small. The server renders the first page,
 * so this module's job is to keep the table in sync afterwards: it owns the
 * state object, talks to one JSON endpoint, and repaints only the parts that
 * changed. Everything beyond a plain table (filter builder, views, column
 * picker, editing, actions, transfer, spreadsheet) lives in a separate module
 * that is imported the first time it is actually needed.
 */

import { csrfToken, debounce, el, get } from './dom.js';

export { debounce, el };

/*
 * The registry lives on window, not in module scope.
 *
 * This module is served from a versioned URL, and an application that also
 * bundles it would produce a second module instance. Sharing the registry means
 * the two instances still agree on which tables already exist, so a table is
 * never mounted — or bound to listeners — twice.
 */
const registry = (window.__dynamicTableRegistry ??= new Map());

/* ------------------------------------------------------------------ */
/* The table                                                           */
/* ------------------------------------------------------------------ */

export class DynamicTable {
    constructor(root, boot) {
        this.root = root;
        this.boot = boot;
        this.key = boot.key;
        this.classes = boot.classes || {};
        this.features = boot.features || {};
        this.columns = boot.columns || [];
        this.permissions = boot.permissions || {};
        this.endpoints = boot.endpoints || {};
        this.labels = boot.labels || {};
        this.state = normalizeState(boot.state || {}, this.columns);
        this.data = boot.data || { rows: [], total: 0, page: 1, lastPage: 1 };
        this.selection = { mode: 'include', ids: new Set() };
        this.listeners = new Map();
        this.modules = new Map();
        this.pending = null;
        this.requestId = 0;
        this.opening = null;
        this._dialog = null;
        this.appending = false;
        this.loadingMore = false;

        this.bind();
        this.watchSentinel();
        this.renderPagination();
        this.emit('ready', this);
    }

    /* ---------------------------------------------------------- */
    /* Events                                                      */
    /* ---------------------------------------------------------- */

    on(event, handler) {
        if (!this.listeners.has(event)) this.listeners.set(event, new Set());
        this.listeners.get(event).add(handler);

        return () => this.listeners.get(event)?.delete(handler);
    }

    emit(event, payload) {
        this.listeners.get(event)?.forEach((handler) => {
            try {
                handler(payload, this);
            } catch (error) {
                console.error('[DynamicTable]', event, error);
            }
        });

        this.root.dispatchEvent(new CustomEvent(`dynamic-table:${event}`, { detail: { table: this, payload }, bubbles: true }));
    }

    t(key, replace = {}) {
        let value = get(this.labels, key, key);

        if (typeof value !== 'string') return key;

        for (const [token, replacement] of Object.entries(replace)) {
            value = value.replace(new RegExp(`:${token}`, 'g'), String(replacement));
        }

        return value;
    }

    /* ---------------------------------------------------------- */
    /* Networking                                                  */
    /* ---------------------------------------------------------- */

    async post(url, body = {}, options = {}) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ table: this.key, ...body }),
            signal: options.signal,
        });

        if (response.status === 204) return null;

        const payload = response.headers.get('content-type')?.includes('application/json')
            ? await response.json()
            : await response.text();

        if (!response.ok) {
            const error = new Error(typeof payload === 'object' ? payload.message || this.t('errors.generic') : payload);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    }

    /** Reload the current page of rows. */
    async refresh({ resetPage = false, silent = false } = {}) {
        if (resetPage) this.state.page = 1;

        this.pending?.abort();
        const controller = new AbortController();
        this.pending = controller;

        const id = ++this.requestId;

        if (!silent) this.setLoading(true);

        try {
            const response = await this.post(this.endpoints.data, { state: this.serializeState() }, { signal: controller.signal });

            if (id !== this.requestId) return;

            this.data = response.data;

            // Appending keeps every row already on screen, so the range starts
            // at the first row of the first page, not of this one.
            if (this.appending) this.data.from = 1;
            this.state = { ...this.state, ...normalizeState(response.state || {}, this.columns) };

            this.renderRows();
            this.renderPagination();
            this.renderSummary();
            this.renderSortIndicators();
            this.syncUrl();
            this.showWarnings(response.data.warnings);
            this.renderDebug(response.data.debug);
            this.emit('updated', response);
        } catch (error) {
            if (error.name === 'AbortError') return;
            this.alert(error.message || this.t('error'), 'error');
            this.emit('error', error);
        } finally {
            if (id === this.requestId) {
                this.pending = null;
                if (!silent) this.setLoading(false);
            }
        }
    }

    serializeState() {
        const state = {
            search: this.state.search || undefined,
            columnSearch: Object.keys(this.state.columnSearch || {}).length ? this.state.columnSearch : undefined,
            filters: this.state.filters && this.state.filters.conditions?.length ? this.state.filters : undefined,
            sort: this.state.sort?.length ? this.state.sort : undefined,
            page: this.state.page,
            perPage: this.state.perPage,
            columns: this.state.columns,
            widths: Object.keys(this.state.widths || {}).length ? this.state.widths : undefined,
            group: this.state.group || undefined,
            trashed: this.state.trashed && this.state.trashed !== 'without' ? this.state.trashed : undefined,
            view: this.state.view || undefined,
        };

        if (this.selection.mode === 'exclude' || this.selection.ids.size) {
            state.selection = { mode: this.selection.mode, ids: [...this.selection.ids] };
        }

        return state;
    }

    /* ---------------------------------------------------------- */
    /* Rendering                                                   */
    /* ---------------------------------------------------------- */

    visibleColumns() {
        const byKey = new Map(this.columns.map((column) => [column.key, column]));

        return (this.state.columns || [])
            .map((key) => byKey.get(key))
            .filter(Boolean);
    }

    renderRows() {
        const body = this.root.querySelector('[data-dt-body]');
        if (!body) return;

        const columns = this.visibleColumns();
        const selectable = !!this.features.selection;
        const fragment = document.createDocumentFragment();

        const span = columns.length + (selectable ? 1 : 0) + (this.features.row_detail ? 1 : 0)
            + ((this.boot.rowActions || []).length ? 1 : 0);

        if (!this.data.rows.length) {
            fragment.append(
                el('tr', {}, [
                    el('td', { class: this.classes.empty, colspan: span, text: this.t('empty') }),
                ]),
            );
        }

        const groupKey = this.features.grouping ? this.state.group : null;
        let lastGroup;

        for (const row of this.data.rows) {
            // The server already ordered by the group column, so a change of
            // value is all we need to start a new group.
            if (groupKey) {
                const value = row.c?.[groupKey] ?? null;

                if (value !== lastGroup) {
                    lastGroup = value;
                    fragment.append(this.renderGroupRow(groupKey, value, span));
                }
            }

            fragment.append(this.renderRow(row, columns, selectable));
        }

        // Infinite scrolling appends: replacing the body would throw away every
        // page the reader has already scrolled past.
        if (this.appending) body.append(fragment);
        else body.replaceChildren(fragment);

        this.emit('rows-rendered', this.data.rows);
    }

    renderGroupRow(key, value, span) {
        const column = this.columns.find((candidate) => candidate.key === key);

        return el('tr', { class: `${this.classes.group} dt-group-row` }, [
            el('td', { colspan: span }, [
                el('span', { class: 'dt-group-label', text: `${column?.label ?? key}: ` }),
                el('strong', { text: value === null || value === '' ? '—' : String(value) }),
            ]),
        ]);
    }

    renderRow(row, columns, selectable) {
        const selected = this.isSelected(row.id);

        const tr = el('tr', {
            class: [this.classes.row, selected ? this.classes.rowSelected : null],
            'data-dt-row': row.id,
            'data-trashed': row.trashed ? '' : null,
        });

        if (this.features.row_detail) {
            tr.append(
                el('td', { class: `${this.classes.cell} dt-expand-cell` }, [
                    el('button', {
                        type: 'button',
                        class: 'dt-expand',
                        'data-dt-detail': row.id,
                        'aria-expanded': 'false',
                        'aria-label': this.t('detail.toggle'),
                        text: '\u203a',
                    }),
                ]),
            );
        }

        if (selectable) {
            tr.append(
                el('td', { class: `${this.classes.cell} dt-select-cell` }, [
                    el('input', {
                        type: 'checkbox',
                        'data-dt-select': row.id,
                        'aria-label': this.t('select_row'),
                        checked: selected,
                    }),
                ]),
            );
        }

        for (const column of columns) {
            const td = el('td', {
                class: [this.classes.cell, `dt-align-${column.align || 'start'}`, column.class],
                'data-dt-cell': column.key,
                'data-label': column.label,
                'data-dt-editable': column.editable && this.features.inline_edit ? '' : null,
                'data-dt-sticky': (this.boot.sticky || []).includes(column.key) ? '' : null,
            });

            this.paintCell(td, column, row.c?.[column.key], row);
            tr.append(td);
        }

        const rowActions = this.boot.rowActions || [];

        if (rowActions.length) {
            const cell = el('td', { class: `${this.classes.cell} dt-row-actions-cell` });

            for (const action of rowActions) {
                // The server decided per record which actions apply; absent
                // means this row does not get that button.
                const applies = row.a?.[action.name];

                if (applies === undefined) continue;

                const shared = {
                    class: ['dt-row-action', action.destructive ? 'dt-row-action-danger' : null],
                    title: action.label,
                    text: action.icon || action.label,
                };

                cell.append(action.link
                    ? el('a', { ...shared, href: applies, target: action.target || null, rel: action.target ? 'noopener' : null })
                    : el('button', { ...shared, type: 'button', 'data-dt-row-action': action.name }));
            }

            tr.append(cell);
        }

        return tr;
    }

    /** Mirrors resources/views/partials/cell.blade.php. */
    paintCell(td, column, value, row) {
        td.replaceChildren();

        if (value === null || value === undefined || value === '') {
            td.append(el('span', { class: 'dt-null', text: '—', 'aria-hidden': 'true' }));

            return;
        }

        if (column.raw || column.format === 'raw') {
            td.innerHTML = value;

            return;
        }

        switch (column.type) {
            case 'boolean':
                td.append(el('span', {
                    class: `dt-bool ${value ? 'dt-bool-true' : 'dt-bool-false'}`,
                    title: value ? this.t('yes') : this.t('no'),
                    text: value ? '✓' : '✕',
                }));
                break;
            case 'enum':
                td.append(el('span', { class: `${this.classes.badge || 'dt-badge'}`, text: String(value) }));
                break;
            case 'image':
                td.append(el('img', { src: value, alt: '', class: 'dt-thumb', loading: 'lazy' }));
                break;
            case 'url':
                td.append(el('a', {
                    href: value,
                    class: 'dt-link',
                    rel: 'noopener noreferrer',
                    target: '_blank',
                    text: String(value).length > 40 ? `${String(value).slice(0, 40)}…` : value,
                }));
                break;
            case 'email':
                td.append(el('a', { href: `mailto:${value}`, class: 'dt-link', text: value }));
                break;
            default:
                td.textContent = String(value);
        }
    }

    renderPagination() {
        const nav = this.root.querySelector('[data-dt-pagination]');
        if (!nav) return;

        const { page, lastPage, counted, hasMore } = this.data;
        nav.replaceChildren();

        const button = (label, target, { disabled = false, current = false } = {}) =>
            el('button', {
                type: 'button',
                class: [this.classes.button, 'dt-page', current ? 'dt-page-current' : null],
                disabled,
                'aria-current': current ? 'page' : null,
                'aria-label': typeof label === 'number' ? `${this.t('page')} ${label}` : label,
                text: String(label),
                onclick: () => this.goToPage(target),
            });

        // Without a count there is no last page and no page list — only
        // whether another page exists. Showing invented numbers would be worse
        // than showing none.
        if (counted === false) {
            if (page <= 1 && !hasMore) return;

            nav.append(
                button('‹', page - 1, { disabled: page <= 1 }),
                el('span', { class: 'dt-page-current-only', text: String(page) }),
                button('›', page + 1, { disabled: !hasMore }),
            );

            return;
        }

        if (!lastPage || lastPage <= 1) return;

        nav.append(button('‹', page - 1, { disabled: page <= 1 }));

        for (const target of pageWindow(page, lastPage)) {
            if (target === '…') {
                nav.append(el('span', { class: 'dt-page-gap', text: '…' }));
            } else {
                nav.append(button(target, target, { current: target === page }));
            }
        }

        nav.append(button('›', page + 1, { disabled: page >= lastPage }));
    }

    renderSummary() {
        const summary = this.root.querySelector('[data-dt-summary]');

        if (! summary) return;

        const number = (value) => new Intl.NumberFormat(this.boot.locale || undefined).format(value);
        const replace = { from: number(this.data.from || 0), to: number(this.data.to || 0) };

        if (this.data.counted !== false) {
            summary.textContent = this.t('showing', { ...replace, total: number(this.data.total || 0) });

            return;
        }

        // Not counted: show the table's approximate size when it is meaningful,
        // rather than a range with nothing to measure it against.
        summary.textContent = this.data.estimate
            ? this.t('showing_estimated', { ...replace, total: number(this.data.estimate) })
            : this.t('showing_uncounted', replace);
    }

    renderSortIndicators() {
        const sort = new Map((this.state.sort || []).map((entry) => [entry.field, entry.direction]));

        this.root.querySelectorAll('[data-dt-column]').forEach((th) => {
            const key = th.getAttribute('data-dt-column');
            const direction = sort.get(key);
            const icon = th.querySelector('.dt-sort-icon');

            if (icon) icon.textContent = direction ? (direction === 'asc' ? '▲' : '▼') : '';
            if (th.hasAttribute('aria-sort')) {
                th.setAttribute('aria-sort', direction ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
            }
        });
    }

    renderDebug(debug) {
        const panel = this.root.querySelector('[data-dt-panel]');
        if (!panel || !debug) return;

        panel.textContent = `${debug.ms} ms · ${debug.memory} MB · eager: ${(debug.relations || []).join(', ') || 'none'}`;
    }

    /** Rebuild the header when the column set or order changed. */
    renderHeader() {
        const headRow = this.root.querySelector('[data-dt-table] thead tr');
        if (!headRow) return;

        const columns = this.visibleColumns();
        const selectable = !!this.features.selection;
        const cells = [];

        if (selectable) {
            cells.push(el('th', { class: `${this.classes.th} dt-select-cell`, scope: 'col' }, [
                el('input', { type: 'checkbox', 'data-dt-select-all': '', 'aria-label': this.t('select_all') }),
            ]));
        }

        for (const column of columns) {
            const width = this.state.widths?.[column.key] || column.width;

            const th = el('th', {
                class: [this.classes.th, column.sortable ? this.classes.thSortable : null, `dt-align-${column.align || 'start'}`],
                scope: 'col',
                'data-dt-column': column.key,
                style: width ? `width:${width}px` : null,
                'aria-sort': column.sortable ? 'none' : null,
            });

            if (column.sortable) {
                th.append(el('button', { type: 'button', class: 'dt-sort', 'data-dt-sort': column.key }, [
                    el('span', { text: column.label }),
                    el('span', { class: 'dt-sort-icon', 'aria-hidden': 'true' }),
                ]));
            } else {
                th.append(el('span', { text: column.label }));
            }

            if ((this.boot.modules || []).includes('header-menu')) {
                th.append(el('button', {
                    type: 'button',
                    class: 'dt-header-menu',
                    'data-dt-header-menu': column.key,
                    'aria-haspopup': 'menu',
                    'aria-expanded': 'false',
                    'aria-label': this.t('header.menu', { column: column.label }),
                    text: '⌄',
                }));
            }

            if (this.features.column_resizing) {
                th.append(el('span', {
                    class: this.classes.resizer,
                    'data-dt-resizer': column.key,
                    role: 'separator',
                    'aria-orientation': 'vertical',
                }));
            }

            cells.push(th);
        }

        headRow.replaceChildren(...cells);
        this.renderSortIndicators();
        this.emit('header-rendered');
    }

    setLoading(loading) {
        const indicator = this.root.querySelector('[data-dt-loading]');
        if (indicator) indicator.hidden = !loading;

        this.root.classList.toggle('dt-is-loading', loading);
        this.root.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    alert(message, kind = 'info', { timeout = 6000, action = null } = {}) {
        const host = this.root.querySelector('[data-dt-alerts]');
        if (!host) return;

        const node = el('div', { class: `dt-alert dt-alert-${kind}`, role: kind === 'error' ? 'alert' : 'status' }, [
            el('span', { text: message }),
            action ? el('button', { type: 'button', class: this.classes.button, text: action.label, onclick: action.handler }) : null,
            el('button', { type: 'button', class: 'dt-alert-close', 'aria-label': this.t('close'), text: '×', onclick: () => node.remove() }),
        ]);

        host.append(node);

        if (timeout) setTimeout(() => node.remove(), timeout);

        return node;
    }

    showWarnings(warnings) {
        if (!warnings?.length) return;

        this.alert(this.t('invalid_fields'), 'warning');
    }

    /* ---------------------------------------------------------- */
    /* Interaction                                                 */
    /* ---------------------------------------------------------- */

    bind() {
        const search = this.root.querySelector('[data-dt-search]');

        if (search) {
            const run = debounce(() => {
                this.state.search = search.value.trim();
                this.refresh({ resetPage: true });
            }, this.boot.searchDebounce || 350);

            search.addEventListener('input', run);
            search.addEventListener('search', run);
        }

        this.root.querySelectorAll('[data-dt-column-search]').forEach((input) => {
            input.addEventListener('input', debounce(() => {
                const key = input.getAttribute('data-dt-column-search');
                this.state.columnSearch = this.state.columnSearch || {};

                if (input.value.trim() === '') delete this.state.columnSearch[key];
                else this.state.columnSearch[key] = input.value.trim();

                this.refresh({ resetPage: true });
            }, 350));
        });

        const perPage = this.root.querySelector('[data-dt-per-page]');

        if (perPage) {
            perPage.addEventListener('change', () => {
                this.state.perPage = Number(perPage.value);
                // Changing the page size also re-pages, so the same rule applies.
                this.scrollIntoView();
                this.refresh({ resetPage: true });
            });
        }

        // Header interactions are delegated so a re-rendered header keeps working.
        this.root.addEventListener('click', (event) => {
            const sortButton = event.target.closest('[data-dt-sort]');

            if (sortButton && this.root.contains(sortButton)) {
                this.toggleSort(sortButton.getAttribute('data-dt-sort'), event.shiftKey);

                return;
            }

            const opener = event.target.closest('[data-dt-open]');

            if (opener && this.root.contains(opener)) {
                event.preventDefault();
                this.open(opener.getAttribute('data-dt-open'), opener);
            }
        });

        this.root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.emit('escape', event);
        });

        window.addEventListener('popstate', () => {
            if (this.features.url_state) this.readUrl();
        });
    }

    toggleSort(key, additive = false) {
        const column = this.columns.find((candidate) => candidate.key === key);
        if (!column?.sortable) return;

        const existing = (this.state.sort || []).find((entry) => entry.field === key);
        let sort = additive ? [...(this.state.sort || [])] : [];

        if (existing) {
            if (existing.direction === 'asc') {
                sort = additive
                    ? sort.map((entry) => (entry.field === key ? { ...entry, direction: 'desc' } : entry))
                    : [{ field: key, direction: 'desc' }];
            } else {
                sort = sort.filter((entry) => entry.field !== key);
            }
        } else {
            sort.push({ field: key, direction: 'asc' });
        }

        this.state.sort = sort.slice(0, 3);
        this.refresh({ resetPage: true });
    }

    /**
     * Infinite scrolling.
     *
     * The same paged endpoint, appended instead of replaced: the server never
     * has to hand out an unbounded result set, and "next page" is still one
     * LIMIT/OFFSET away.
     */
    watchSentinel() {
        const sentinel = this.root.querySelector('[data-dt-sentinel]');

        if (! sentinel || typeof IntersectionObserver === 'undefined') return;

        // Watch against whatever actually scrolls. When the table has its own
        // height that is the scroller, and the page never moves; when it does
        // not, it is the viewport. Getting this wrong either never fires or
        // fires forever.
        this.observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) this.loadMore();
        }, { root: scrollParent(sentinel), rootMargin: '300px' });

        this.observer.observe(sentinel);
    }

    hasMorePages() {
        return this.data.counted === false
            ? !! this.data.hasMore
            : this.state.page < (this.data.lastPage || 1);
    }

    async loadMore() {
        if (this.loadingMore || this.pending || ! this.hasMorePages()) return;

        this.loadingMore = true;
        this.appending = true;
        this.state.page += 1;

        try {
            await this.refresh();
        } finally {
            this.appending = false;
            this.loadingMore = false;
        }
    }

    goToPage(page) {
        // Without a count there is no known last page, so the only ceiling is
        // "there is a next one".
        const ceiling = this.data.counted === false
            ? (this.data.hasMore ? this.state.page + 1 : this.state.page)
            : (this.data.lastPage || 1);

        const target = Math.max(1, Math.min(page, ceiling));

        if (target === this.state.page) return;

        this.state.page = target;

        // Scroll before the fetch, not after: the loading overlay is already
        // showing, so moving straight away feels immediate rather than jumpy.
        this.scrollIntoView();
        this.refresh();
    }

    /**
     * Bring the top of the table back into view.
     *
     * Only when it has actually scrolled off the top — paging a short table
     * that is fully visible should not move the page at all. Apps with a fixed
     * header can tune the resting position with CSS:
     *
     *     .dt { scroll-margin-top: 5rem; }
     */
    scrollIntoView() {
        if (this.boot.scrollOnPage === false) return;

        const scroller = this.root.querySelector('[data-dt-scroller]');

        // A table with its own height scrolls itself: moving the page instead
        // would leave the reader looking at the middle of the new page.
        if (scroller && scroller.scrollHeight > scroller.clientHeight) {
            scroller.scrollTo({
                top: 0,
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            });

            return;
        }

        const { top } = this.root.getBoundingClientRect();

        if (top >= 0) return;

        this.root.scrollIntoView({
            block: 'start',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
    }

    setColumns(keys, { widths = null } = {}) {
        this.state.columns = keys;
        if (widths) this.state.widths = widths;

        this.renderHeader();
        this.refresh({ resetPage: false });
    }

    applyView(configuration, viewId = null) {
        this.state = {
            ...this.state,
            ...normalizeState(configuration || {}, this.columns),
            page: 1,
            view: viewId,
        };

        const search = this.root.querySelector('[data-dt-search]');
        if (search) search.value = this.state.search || '';

        this.renderHeader();
        this.refresh();
    }

    /* ---------------------------------------------------------- */
    /* Selection (shared with the actions module)                  */
    /* ---------------------------------------------------------- */

    isSelected(id) {
        const has = this.selection.ids.has(String(id));

        return this.selection.mode === 'exclude' ? !has : has;
    }

    selectionCount() {
        return this.selection.mode === 'exclude'
            ? Math.max(0, (this.data.total || 0) - this.selection.ids.size)
            : this.selection.ids.size;
    }

    /* ---------------------------------------------------------- */
    /* URL state                                                   */
    /* ---------------------------------------------------------- */

    syncUrl() {
        if (!this.features.url_state) return;

        const url = new URL(window.location.href);
        const prefix = `${this.key}_`;
        const set = (name, value) => {
            if (value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length)) {
                url.searchParams.delete(prefix + name);
            } else {
                url.searchParams.set(prefix + name, Array.isArray(value) ? value.join(',') : String(value));
            }
        };

        set('search', this.state.search);
        set('page', this.state.page > 1 ? this.state.page : null);
        set('perPage', this.state.perPage);
        set('sort', (this.state.sort || []).map((entry) => (entry.direction === 'desc' ? `-${entry.field}` : entry.field)));
        set('view', this.state.view);
        set('filters', this.state.filters?.conditions?.length ? JSON.stringify(this.state.filters) : null);

        window.history.replaceState({}, '', url);
    }

    readUrl() {
        const url = new URL(window.location.href);
        const prefix = `${this.key}_`;
        const value = (name) => url.searchParams.get(prefix + name);

        this.state.search = value('search') || '';
        this.state.page = Number(value('page') || 1);

        const filters = value('filters');
        if (filters) {
            try {
                this.state.filters = JSON.parse(filters);
            } catch { /* ignore malformed URLs */ }
        }

        this.refresh();
    }

    /* ---------------------------------------------------------- */
    /* Lazy modules                                                */
    /* ---------------------------------------------------------- */

    async load(name) {
        if (this.modules.has(name)) return this.modules.get(name);

        // Relative to this module's own URL, which already carries the version
        // in its path — so a module and everything it imports resolve to one
        // versioned directory, and therefore to one instance each.
        const promise = import(new URL(`./${name}.js`, import.meta.url).href)
            .then((module) => module.default(this))
            .catch((error) => {
                console.error(`[DynamicTable] failed to load module "${name}"`, error);
                this.modules.delete(name);
                throw error;
            });

        this.modules.set(name, promise);

        return promise;
    }

    /**
     * Panels are opened on demand, which is when their module first loads.
     *
     * Opening is async, so a second click can arrive while the first import is
     * still in flight. The guard makes that a no-op instead of a second,
     * identical panel stacked on the first.
     */
    async open(panel, trigger = null) {
        const moduleFor = {
            filters: 'filters',
            views: 'views',
            columns: 'columns',
            actions: 'actions',
            'bulk-edit': 'actions',
            export: 'transfer',
            import: 'transfer',
        }[panel];

        // The lock lives on the element, not just on this object, so a second
        // runtime sharing the same DOM cannot open a second copy of the panel.
        if (!moduleFor || this.opening || this.root.dataset.dtOpening) return;

        this.opening = panel;
        this.root.dataset.dtOpening = panel;

        try {
            const api = await this.load(moduleFor);
            await api?.open?.(panel, trigger);
        } catch (error) {
            this.alert(this.t('errors.generic'), 'error');
            console.error('[DynamicTable] could not open panel', panel, error);
        } finally {
            this.opening = null;
            delete this.root.dataset.dtOpening;
        }
    }
}

/* ------------------------------------------------------------------ */
/* State + bootstrapping                                               */
/* ------------------------------------------------------------------ */

/**
 * The nearest ancestor that actually scrolls vertically, or null for the page.
 *
 * "Has overflow: auto" is not enough — a box whose content fits scrolls
 * nothing, and treating it as the scroll root would make an observer fire
 * immediately and forever.
 */
function scrollParent(node) {
    for (let element = node?.parentElement; element; element = element.parentElement) {
        const { overflowY } = getComputedStyle(element);

        if (/(auto|scroll|overlay)/.test(overflowY) && element.scrollHeight > element.clientHeight) {
            return element;
        }
    }

    return null;
}

function normalizeState(state, columns) {
    const valid = new Set(columns.map((column) => column.key));

    return {
        search: state.search || '',
        columnSearch: state.columnSearch || {},
        filters: state.filters || null,
        sort: Array.isArray(state.sort) ? state.sort : [],
        page: Number(state.page || 1),
        perPage: Number(state.perPage || 25),
        columns: Array.isArray(state.columns) && state.columns.length
            ? state.columns.filter((key) => valid.has(key))
            : columns.filter((column) => column.visible).map((column) => column.key),
        widths: state.widths || {},
        group: state.group || null,
        trashed: state.trashed || 'without',
        view: state.view || null,
    };
}

function pageWindow(page, lastPage) {
    const pages = new Set([1, lastPage, page, page - 1, page + 1, page - 2, page + 2]);
    const sorted = [...pages].filter((value) => value >= 1 && value <= lastPage).sort((a, b) => a - b);
    const output = [];

    sorted.forEach((value, index) => {
        if (index > 0 && value - sorted[index - 1] > 1) output.push('…');
        output.push(value);
    });

    return output;
}

export function mount(root) {
    if (registry.has(root)) return registry.get(root);

    // A DOM marker as well as the registry: if the core module ends up loaded
    // twice (bundled by the app *and* injected by the directive), each copy has
    // its own registry, and without this both would bind their own listeners —
    // which is how one click ends up opening two identical panels.
    if (root.dataset.dtMounted === 'true') return null;

    const script = root.querySelector('script[data-dt-boot]');
    if (!script) return null;

    let boot;

    try {
        boot = JSON.parse(script.textContent);
    } catch (error) {
        console.error('[DynamicTable] invalid boot payload', error);

        return null;
    }

    root.dataset.dtMounted = 'true';

    const table = new DynamicTable(root, boot);
    registry.set(root, table);
    registry.set(boot.key, table);

    // Only modules that must be live before any user interaction are warmed;
    // panels (filters, views, columns, transfer) load when first opened.
    for (const name of boot.modules || []) {
        if (['actions', 'inline-edit', 'spreadsheet', 'responsive', 'header-menu', 'detail', 'sticky'].includes(name)) table.load(name);
    }

    return table;
}

export function boot(scope = document) {
    scope.querySelectorAll('[data-dynamic-table]').forEach(mount);
}

export function find(key) {
    return registry.get(key) || null;
}

if (typeof window !== 'undefined') {
    window.DynamicTable = { boot, mount, find, el, debounce };

    // Global, so a second copy of this module cannot register a second set of
    // navigation listeners and boot everything again.
    if (! window.__dynamicTableStarted) {
        window.__dynamicTableStarted = true;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => boot());
        } else {
            boot();
        }

        // Play nicely with Livewire/Turbo style navigation.
        document.addEventListener('livewire:navigated', () => boot());
        document.addEventListener('turbo:load', () => boot());
    }
}

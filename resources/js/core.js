/**
 * Laravel DynamicTable — core runtime.
 *
 * Deliberately dependency-free and small. The server renders the first page,
 * so this module's job is to keep the table in sync afterwards: it owns the
 * state object, talks to one JSON endpoint, and repaints only the parts that
 * changed. Everything beyond a plain table (filter builder, views, column
 * picker, editing, actions, transfer) lives in a separate module
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
        this.syncSizedLayout();
        this.syncHeaderOffset();
        this.watchSentinel();
        this.renderPagination();
        this.renderFilterIndicators();
        this.syncPrintLink();
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

            // Definitions for columns the picker added. They have to be learned
            // *before* the state is normalised, because normalising drops keys
            // this runtime has no definition for.
            const learned = this.learnColumns(response.data.columns);

            // Appending keeps every row already on screen, so the range starts
            // at the first row of the first page, not of this one.
            if (this.appending) this.data.from = 1;
            this.state = { ...this.state, ...normalizeState(response.state || {}, this.columns) };

            if (learned) this.renderHeader();

            this.renderRows();
            this.renderSummaryRow();
            this.syncPrintLink();
            this.renderPagination();
            this.renderSummary();
            this.renderSortIndicators();
            this.renderFilterIndicators();
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
            params: Object.keys(this.state.params || {}).length ? this.state.params : undefined,
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
            fragment.append(el('tr', {}, [
                el('td', { class: this.classes.empty, colspan: span }, [this.renderEmpty()]),
            ]));
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

        this.syncSizedLayout();
        this.emit('rows-rendered', this.data.rows);
    }

    /**
     * The empty state, which is two different messages.
     *
     * "There are no records" is a fact about the data and there is nothing to
     * do about it. "Nothing matches your filters" is a fact about the *state*,
     * and the useful thing is a way out of it — so that one, and only that
     * one, gets a button.
     */
    renderEmpty() {
        const filtered = this.data.emptyReason === 'filtered';

        return el('div', { class: 'dt-empty-state', 'data-dt-empty': '' }, [
            el('p', { class: 'dt-empty-title', text: this.t(filtered ? 'empty_filtered' : 'empty') }),
            filtered ? el('p', { class: 'dt-empty-hint', text: this.t('empty_filtered_hint') }) : null,
            filtered
                ? el('button', {
                    type: 'button',
                    class: this.classes.button,
                    text: this.t('clear_filters'),
                    'data-dt-clear-filters': '',
                })
                : null,
        ]);
    }

    /**
     * Undo everything that narrowed the result, and nothing else.
     *
     * The columns, the sort and the page size are how the reader likes to look
     * at the table; they did not cause the empty result and are left alone.
     */
    clearFilters() {
        this.state.search = '';
        this.state.columnSearch = {};
        this.state.filters = null;
        this.state.trashed = 'without';

        const search = this.root.querySelector('[data-dt-search]');

        if (search) search.value = '';

        this.root.querySelectorAll('[data-dt-column-search]').forEach((input) => { input.value = ''; });

        this.refresh({ resetPage: true });
        this.emit('filters-cleared');
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

    /**
     * The aggregate row under the table.
     *
     * The server sends it already formatted — a total under a currency column
     * has to read as currency, and that formatting lives in one place, on the
     * server, so the export and the screen cannot disagree.
     */
    /**
     * Keep the print link pointing at what the reader is actually looking at.
     *
     * It stays a plain link — printing is a page, and a page is a URL, so it
     * opens in a tab, can be reloaded, and can be sent to someone. That means
     * the current state has to travel in the href rather than in a fetch body.
     */
    syncPrintLink() {
        const link = this.root.querySelector('[data-dt-print]');

        if (! link) return;

        const url = new URL(link.href, window.location.origin);

        url.searchParams.set('table', this.key);
        url.searchParams.set('state', JSON.stringify(this.serializeState()));

        link.href = url.toString();
    }

    renderSummaryRow() {
        const foot = this.root.querySelector('[data-dt-summary-row]');

        if (! foot) return;

        const summaries = this.data.summaries || {};

        foot.hidden = Object.keys(summaries).length === 0;

        for (const cell of foot.querySelectorAll('[data-dt-summary]')) {
            const key = cell.getAttribute('data-dt-summary');
            const column = this.columns.find((candidate) => candidate.key === key);
            const value = summaries[key];

            cell.replaceChildren();

            if (value === undefined) continue;

            cell.append(
                el('span', { class: 'dt-summary-label', text: this.t(`summary.${column?.summary || 'sum'}`) }),
                el('span', { class: 'dt-summary-value', text: String(value) }),
            );
        }
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

    /**
     * Column keys that some condition in the filter tree is about, at any depth.
     *
     * The header marker is derived from the tree rather than tracked alongside
     * it, so a filter set in the builder, in the header menu or by a saved view
     * all light up the same way and none of them can drift.
     */
    filteredColumns() {
        const keys = new Set();

        const walk = (node) => {
            for (const child of node?.conditions || []) {
                if (child.conditions) walk(child);
                else if (child.field) keys.add(String(child.field).replace(/\./g, '__'));
            }
        };

        walk(this.state.filters);

        return [...keys];
    }

    renderFilterIndicators() {
        const filtered = new Set(this.filteredColumns());

        this.root.querySelectorAll('[data-dt-column]').forEach((th) => {
            const on = filtered.has(th.getAttribute('data-dt-column'));

            th.toggleAttribute('data-dt-filtered', on);

            let marker = th.querySelector('.dt-filtered-icon');

            if (on && ! marker) {
                marker = el('span', { class: 'dt-filtered-icon', 'aria-hidden': 'true', text: '▼' });
                (th.querySelector('.dt-header-trigger') || th).append(marker);
            } else if (! on && marker) {
                marker.remove();
            }
        });
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

        // The expander and the row buttons are cells of the header too. Leaving
        // them out here would shift every column one place the first time the
        // header is repainted.
        if (this.features.row_detail) {
            cells.push(el('th', { class: `${this.classes.th} dt-expand-cell`, scope: 'col' }, [
                el('span', { class: 'dt-visually-hidden', text: this.t('detail.title') }),
            ]));
        }

        if (selectable) {
            cells.push(el('th', { class: `${this.classes.th} dt-select-cell`, scope: 'col' }, [
                el('input', { type: 'checkbox', 'data-dt-select-all': '', 'aria-label': this.t('select_all') }),
            ]));
        }

        const headerMenu = (this.boot.modules || []).includes('header-menu');

        for (const column of columns) {
            const width = this.state.widths?.[column.key] || column.width;

            const th = el('th', {
                class: [this.classes.th, column.sortable && ! headerMenu ? this.classes.thSortable : null, `dt-align-${column.align || 'start'}`],
                scope: 'col',
                'data-dt-column': column.key,
                style: width ? `width:${width}px` : null,
                'aria-sort': column.sortable ? 'none' : null,
            });

            // With the header menu on, the header opens the menu rather than
            // sorting: both sort directions are in that menu, and a header that
            // does both makes one of them an accident. Mirrors the template.
            if (headerMenu) {
                th.append(el('button', {
                    type: 'button',
                    class: 'dt-header-trigger',
                    'data-dt-header-menu': column.key,
                    'aria-haspopup': 'menu',
                    'aria-expanded': 'false',
                    'aria-label': this.t('header.menu', { column: column.label }),
                }, [
                    el('span', { text: column.label }),
                    el('span', { class: 'dt-sort-icon', 'aria-hidden': 'true' }),
                    el('span', { class: 'dt-header-cog', 'aria-hidden': 'true', text: '⚙' }),
                ]));
            } else if (column.sortable) {
                th.append(el('button', { type: 'button', class: 'dt-sort', 'data-dt-sort': column.key }, [
                    el('span', { text: column.label }),
                    el('span', { class: 'dt-sort-icon', 'aria-hidden': 'true' }),
                ]));
            } else {
                th.append(el('span', { text: column.label }));
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

        if ((this.boot.rowActions || []).length) {
            cells.push(el('th', { class: `${this.classes.th} dt-row-actions-cell`, scope: 'col' }, [
                el('span', { class: 'dt-visually-hidden', text: this.t('actions.title') }),
            ]));
        }

        headRow.replaceChildren(...cells);
        this.renderSearchRow();
        this.syncSizedLayout();
        this.syncHeaderOffset();
        this.renderSortIndicators();
        this.renderFilterIndicators();
        this.emit('header-rendered');
    }

    /**
     * The column-search row, rebuilt to match the header above it.
     *
     * One cell per header cell — expander, checkbox and row buttons included —
     * so the inputs stay under the columns they search. The values come from
     * the state rather than from the DOM, so a search survives a column change.
     */
    renderSearchRow() {
        const row = this.root.querySelector('[data-dt-table] [data-dt-search-row]');
        if (!row) return;

        const cells = [];

        if (this.features.row_detail) cells.push(el('th', { class: `${this.classes.th} dt-expand-cell` }));
        if (this.features.selection) cells.push(el('th', { class: `${this.classes.th} dt-select-cell` }));

        for (const column of this.visibleColumns()) {
            const cell = el('th', { class: this.classes.th, 'data-dt-search-cell': column.key });

            if (column.filterable) {
                cell.append(el('input', {
                    type: 'text',
                    class: this.classes.input || '',
                    'data-dt-column-search': column.key,
                    value: this.state.columnSearch?.[column.key] || '',
                    'aria-label': this.t('search_column', { column: column.label }),
                }));
            }

            cells.push(cell);
        }

        if ((this.boot.rowActions || []).length) cells.push(el('th', { class: `${this.classes.th} dt-row-actions-cell` }));

        row.replaceChildren(...cells);
    }

    /**
     * How far down the second header row has to stick.
     *
     * Both rows are sticky, and both would otherwise stop at the top of the
     * scroller — one on top of the other. The offset is measured rather than
     * guessed because a header's height depends on its font, its padding and
     * whether a label wrapped.
     */
    syncHeaderOffset() {
        const element = this.root.querySelector('[data-dt-table]');
        const headRow = element?.querySelector('thead tr');

        if (!element || !headRow) return;

        element.style.setProperty('--dt-head-offset', `${Math.round(headRow.getBoundingClientRect().height)}px`);
    }

    /**
     * Once a column has been given a width, the table stops sharing the
     * container out between columns and honours the widths it was given: fixed
     * layout, sized to its content, scrolling sideways when the total no longer
     * fits. Without this, widening one column can only come out of another.
     */
    syncSizedLayout() {
        const element = this.root.querySelector('[data-dt-table]');
        if (!element) return;

        /*
         * Both kinds of width count: the ones the reader dragged and the ones
         * the column declared. A declared width is rendered as an inline style
         * on the header, and it needs fixed layout just as much — auto layout
         * would refuse to take the column under the width of its own label.
         */
        const widths = { ...(this.state.widths || {}) };

        element.querySelectorAll('thead th[data-dt-column]').forEach((th) => {
            const key = th.getAttribute('data-dt-column');
            const declared = parseInt(th.style.width, 10);

            if (!widths[key] && Number.isFinite(declared)) widths[key] = declared;
        });

        element.classList.toggle('dt-sized', Object.keys(widths).length > 0);

        // The widths go back onto the headers, so a re-render that arrives
        // without them cannot leave the state and the table disagreeing.
        element.querySelectorAll('thead th[data-dt-column]').forEach((th) => {
            const width = widths[th.getAttribute('data-dt-column')];

            if (width) th.style.width = `${width}px`;
        });

        // A column narrowed to the width of "$2" cannot also carry a cell's
        // worth of padding — border-box means the padding would eat the whole
        // column and leave no room even for the ellipsis.
        element.querySelectorAll('.dt-narrow').forEach((cell) => cell.classList.remove('dt-narrow'));

        for (const [key, width] of Object.entries(widths)) {
            if (width >= 64) continue;

            const escaped = CSS.escape(key);

            element.querySelectorAll(
                `th[data-dt-column="${escaped}"], th[data-dt-search-cell="${escaped}"], td[data-dt-cell="${escaped}"]`,
            ).forEach((cell) => cell.classList.add('dt-narrow'));
        }
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

        // Delegated, because the search row is rebuilt whenever the columns
        // change — a listener bound to the input would go with it.
        const columnSearch = debounce(() => this.refresh({ resetPage: true }), 350);

        this.root.addEventListener('input', (event) => {
            const input = event.target.closest?.('[data-dt-column-search]');
            if (!input || !this.root.contains(input)) return;

            const key = input.getAttribute('data-dt-column-search');
            this.state.columnSearch = { ...(this.state.columnSearch || {}) };

            if (input.value.trim() === '') delete this.state.columnSearch[key];
            else this.state.columnSearch[key] = input.value.trim();

            columnSearch();
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

            const print = event.target.closest('[data-dt-print]');

            if (print && this.root.contains(print)) {
                /*
                 * Opened by script, deliberately.
                 *
                 * A tab may only close itself if a script opened it, and the
                 * print page closes itself once the dialog is dismissed. A
                 * plain target="_blank" tab would print and then sit there.
                 * Without JavaScript the link still works — it just stays open.
                 */
                event.preventDefault();
                window.open(print.href, `dt-print-${this.key}`);

                return;
            }

            if (event.target.closest('[data-dt-clear-filters]')) {
                event.preventDefault();
                this.clearFilters();

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

        this.bindParams();

        // A header row that wraps at one width does not wrap at another, and
        // the row below it sticks to whatever it measures now.
        window.addEventListener('resize', debounce(() => this.syncHeaderOffset(), 150));

        window.addEventListener('popstate', () => {
            if (this.features.url_state) this.readUrl();
        });
    }

    /**
     * Controls outside the table that feed query().
     *
     * They live wherever the page puts them — a filter bar above the table, a
     * card in the sidebar — so the listeners are on the document, matched by
     * the table key, rather than on the table's own element.
     */
    bindParams() {
        const scope = this.root.ownerDocument;

        const apply = (options = {}) => this.setParams(this.readParamControls(), options);
        const applyLater = debounce(() => apply(), this.boot.searchDebounce || 350);

        scope.addEventListener('change', (event) => {
            if (event.target.closest?.(this.paramSelector())) apply();
        });

        // Typing is debounced; a date or select fires "change" and applies at once.
        scope.addEventListener('input', (event) => {
            const control = event.target.closest?.(this.paramSelector());

            if (control && ['text', 'search', 'number'].includes(control.type)) applyLater();
        });

        scope.addEventListener('submit', (event) => {
            const form = event.target.closest?.(`form[data-dt-params="${CSS.escape(this.key)}"]`);

            if (form) {
                event.preventDefault();
                apply();
            }
        });

        scope.addEventListener('click', (event) => {
            const reset = event.target.closest?.(`[data-dt-params-reset="${CSS.escape(this.key)}"]`);

            if (reset) {
                event.preventDefault();
                this.resetParams();
            }
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
     * Let go of everything this table holds.
     *
     * Called when a table's element is replaced — an AJAX swap, a Livewire or
     * Turbo navigation — so its observers and in-flight request do not outlive
     * the DOM they were watching.
     */
    destroy() {
        this.observer?.disconnect();
        this.pending?.abort();
        this.listeners.clear();
        this.modules.clear();
        registry.delete(this.root);
        registry.delete(this.key);
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

    /**
     * Teach this runtime about columns it was not booted with.
     *
     * @returns {boolean} whether anything was new, so the caller can repaint.
     */
    learnColumns(definitions) {
        let learned = false;

        for (const definition of definitions || []) {
            const at = this.columns.findIndex((column) => column.key === definition.key);

            if (at === -1) {
                this.columns.push(definition);
                learned = true;
            } else {
                this.columns[at] = { ...this.columns[at], ...definition };
            }
        }

        return learned;
    }

    /**
     * @param {Array|null} definitions for keys this runtime does not know yet —
     *        the picker has them from the field catalogue, so the header can be
     *        drawn at once rather than after the round trip.
     */
    setColumns(keys, { widths = null, definitions = null } = {}) {
        this.learnColumns(definitions);

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
    /* Parameters (the table's own toolbar controls)               */
    /* ---------------------------------------------------------- */

    /** The parameters the next request will carry. */
    getParams() {
        return { ...(this.state.params || {}) };
    }

    /**
     * Set the table's parameters and reload.
     *
     * Merges by default, so one control can set its own value without knowing
     * about the others. A value of null, undefined or "" clears the parameter,
     * which is how "any status" is expressed.
     */
    setParams(params, { merge = true, refresh = true, resetPage = true } = {}) {
        const next = merge ? { ...(this.state.params || {}) } : {};

        for (const [name, value] of Object.entries(params || {})) {
            if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) {
                delete next[name];
            } else {
                next[name] = value;
            }
        }

        this.state.params = next;
        this.emit('params-changed', next);

        if (refresh) this.refresh({ resetPage });

        return next;
    }

    setParam(name, value, options = {}) {
        return this.setParams({ [name]: value }, options);
    }

    /** Drop every parameter the controls have set, and reload. */
    resetParams(options = {}) {
        this.root.ownerDocument.querySelectorAll(this.paramSelector()).forEach((input) => {
            if (input.type === 'checkbox' || input.type === 'radio') input.checked = false;
            else input.value = '';
        });

        return this.setParams({}, { ...options, merge: false });
    }

    paramSelector() {
        return `[data-dt-param][data-dt-table="${CSS.escape(this.key)}"], [data-dt-params="${CSS.escape(this.key)}"] [data-dt-param]`;
    }

    /**
     * Read every bound control at once.
     *
     * Controls are read as a set rather than one at a time so a form that
     * changes two fields — a from/to pair, say — still makes one request.
     */
    readParamControls() {
        const params = {};

        this.root.ownerDocument.querySelectorAll(this.paramSelector()).forEach((input) => {
            const name = input.getAttribute('data-dt-param');
            if (!name) return;

            let value = input.type === 'checkbox' ? (input.checked ? (input.value || true) : '') : input.value;

            if (input.multiple && input.tagName === 'SELECT') {
                value = [...input.selectedOptions].map((option) => option.value).filter(Boolean);
            }

            if (input.type === 'radio' && !input.checked) return;

            params[name] = value;
        });

        return params;
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
        params: state.params || {},
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

    // A table of this key may already exist with an element that has since
    // been replaced. Its observers would otherwise keep running against a
    // detached tree for the life of the page.
    const previous = registry.get(boot.key);

    if (previous && previous.root !== root && ! previous.root.isConnected) {
        previous.destroy();
    }

    const table = new DynamicTable(root, boot);
    registry.set(root, table);
    registry.set(boot.key, table);

    // Only modules that must be live before any user interaction are warmed;
    // panels (filters, views, columns, transfer) load when first opened.
    for (const name of boot.modules || []) {
        if (['actions', 'inline-edit', 'responsive', 'header-menu', 'detail', 'sticky'].includes(name)) table.load(name);
    }

    // Resizing lives in the columns module but is not a panel: its handles are
    // in the header from first paint, so the module has to be there before the
    // first drag rather than when the picker is opened.
    if (boot.features?.column_resizing) table.load('columns');

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

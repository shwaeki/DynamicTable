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

/**
 * One failed request, described.
 *
 * `kind` is what went wrong in terms a caller can branch on — "offline",
 * "expired", "forbidden", "throttled", "server", "request" — and `retryable`
 * says whether running the same request again could plausibly work. A
 * validation error is not retryable; an unreachable network is.
 */
function requestError(kind, message, { status = 0, payload = null, retryable = false, cause = null } = {}) {
    const error = new Error(message, cause ? { cause } : undefined);

    error.name = 'DynamicTableRequestError';
    error.kind = kind;
    error.status = status;
    error.payload = payload;
    error.retryable = retryable;

    return error;
}

/**
 * One group value as a lookup key, exactly as QueryEngine::groupKey() builds
 * it in PHP. The two have to agree: the server keys a group subtotal with one
 * and this renderer looks it up with the other.
 */
function groupKey(value) {
    // A NUL sentinel rather than an empty string: a column holding both null
    // and "" shows two headings, and they must not share one subtotal.
    if (value === null || value === undefined) return '\u0000null';
    if (typeof value === 'boolean') return value ? '1' : '0';

    return String(value);
}

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

        /*
         * Only the slots this runtime repaints — the empty state. The rest were
         * rendered into the page by Blade, in parts of it nothing here rebuilds,
         * so they are not in the payload and do not need to be.
         */
        this.slots = boot.slots || {};
        this.state = normalizeState(boot.state || {}, this.columns);
        this.data = boot.data || { rows: [], total: 0, page: 1, lastPage: 1 };
        this.selection = { mode: 'include', ids: new Set() };
        this.listeners = new Map();
        this.modules = new Map();
        this.pending = null;
        this.requestId = 0;
        this.expired = null;

        /*
         * One signal for every listener this table puts somewhere it does not
         * own — the document, the window.
         *
         * Listeners on the table's own element go with the element. These do
         * not: on a Livewire or Inertia page that swaps the table out on every
         * visit, each visit would otherwise leave behind a resize handler, a
         * popstate handler and four document handlers, all still firing
         * against a tree that is no longer on the page.
         */
        this.teardown = new AbortController();

        /*
         * The keyset cursor for infinite scrolling, when the server sent one.
         *
         * It lives outside this.state on purpose: it is not something a saved
         * view, the URL or the remembered state should ever carry, because it
         * points into one particular result and means nothing in another.
         */
        this.cursor = this.data.nextCursor ?? null;
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

        /*
         * ":to" must not match the front of ":total".
         *
         * It did, and because the replacements ran in the order they were
         * given, "Showing :from–:to of :total" came out as "Showing 1–10 of
         * 10tal". The lookahead makes the order irrelevant: a token only
         * matches where the placeholder actually ends. Laravel's own
         * make_replacements sorts by length for the same reason, which is why
         * the server-rendered first page was always right and only the pages
         * after it were wrong.
         */
        for (const [token, replacement] of Object.entries(replace)) {
            value = value.replace(new RegExp(`:${token}(?![A-Za-z0-9_])`, 'g'), String(replacement));
        }

        return value;
    }

    /* ---------------------------------------------------------- */
    /* Networking                                                  */
    /* ---------------------------------------------------------- */

    async post(url, body = {}, options = {}) {
        return this.send(url, {
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ table: this.key, ...body }),
            signal: options.signal,
        });
    }

    /**
     * A multipart POST — the import analyser hands the server a file.
     *
     * It exists so that an upload lands in the same classifier as everything
     * else: a file chosen on a page whose session has expired says so, rather
     * than reporting that the file could not be read.
     */
    async upload(url, data, options = {}) {
        if (data instanceof FormData && !data.has('table')) data.append('table', this.key);

        // No Content-Type: the browser has to set it, because only it knows
        // the multipart boundary it is about to write.
        return this.send(url, { body: data, signal: options.signal });
    }

    /**
     * Every request the table makes goes through here, so every failure is
     * described the same way and nothing has to be classified twice.
     */
    async send(url, { headers = {}, body = null, signal = null } = {}) {
        let response;

        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                    ...headers,
                },
                body,
                signal,
            });
        } catch (error) {
            /*
             * fetch only rejects when there was no answer at all: the network
             * is down, the host is unreachable, or the caller aborted. An
             * abort is not a failure and every caller already recognises it by
             * name, so it is rethrown untouched.
             */
            if (error.name === 'AbortError') throw error;

            throw requestError('offline', this.t('errors.offline'), { retryable: true, cause: error });
        }

        if (response.status === 204) return null;

        const payload = response.headers.get('content-type')?.includes('application/json')
            ? await response.json()
            : await response.text();

        if (response.ok) return payload;

        throw this.failure(response, payload);
    }

    /**
     * Turn a refused response into an error worth showing a person.
     *
     * The server's own message is kept wherever it said something specific —
     * which validation, authorisation and the import reader all do. The
     * package supplies text only for the statuses where the server's message
     * is either absent or unhelpful: a bare "Server Error" in production tells
     * the reader nothing they can act on.
     */
    failure(response, payload) {
        const said = (payload && typeof payload === 'object' ? payload.message : payload) || '';
        const status = response.status;
        const detail = { status, payload };

        /*
         * 419 is an expired CSRF token and 401 an expired session. They mean
         * one thing to the reader — they were signed in when this page loaded
         * and are not now — and neither is fixable by retrying, so they are
         * announced page-wide instead of per request.
         */
        if (status === 419 || status === 401) {
            this.expire();

            return requestError('expired', this.t('errors.session_expired'), detail);
        }

        if (status === 403) return requestError('forbidden', said || this.t('errors.forbidden'), detail);

        if (status === 429) {
            const seconds = Number(response.headers.get('Retry-After'));
            const message = seconds > 0
                ? this.t('errors.throttled_in', { seconds })
                : this.t('errors.throttled');

            return requestError('throttled', message, { ...detail, retryable: true });
        }

        if (status >= 500) return requestError('server', this.t('errors.server'), { ...detail, retryable: true });

        return requestError('request', said || this.t('errors.generic'), detail);
    }

    /**
     * The session went away under the reader's feet.
     *
     * A page-wide condition rather than one request's problem: everything
     * after this fails identically, and no amount of retrying helps. So it is
     * said once, without a timeout, with the only button that fixes it — and
     * remembered, so a table that had six requests in flight does not stack
     * six identical alerts.
     */
    expire() {
        if (this.expired?.isConnected) return;

        this.expired = this.alert(this.t('errors.session_expired'), 'error', {
            timeout: 0,
            action: { label: this.t('reload'), handler: () => window.location.reload() },
        }) || null;
    }

    /** Reload the current page of rows. */
    async refresh(options = {}) {
        const { resetPage = false, silent = false } = options;

        if (resetPage) this.state.page = 1;

        /*
         * Only loadMore() continues from where the last page ended. Anything
         * else — a search, a filter, a new sort, a different page size — has
         * changed the result the cursor pointed into, so it is thrown away and
         * the server starts from the top.
         */
        if (! this.appending) this.cursor = null;

        this.pending?.abort();
        const controller = new AbortController();
        this.pending = controller;

        const id = ++this.requestId;

        if (!silent) this.setLoading(true);

        try {
            const response = await this.post(this.endpoints.data, { state: this.serializeState() }, { signal: controller.signal });

            if (id !== this.requestId) return;

            this.data = response.data;
            this.cursor = response.data.nextCursor ?? null;

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

            /*
             * A failed load is the one error the reader can do something
             * about, and "try again" beats making them find the reload
             * button — but only where trying again could work. An expired
             * session has already said its piece page-wide, with the only
             * button that helps; a second alert under it is the same news
             * twice, and a Retry there would just fail again.
             */
            if (error.kind !== 'expired') {
                this.alert(error.message || this.t('error'), 'error', {
                    action: error.kind && !error.retryable
                        ? null
                        : { label: this.t('retry'), handler: () => this.refresh(options) },
                });
            }

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
            view: this.state.view || undefined,
            params: Object.keys(this.state.params || {}).length ? this.state.params : undefined,
            // Where the next page of an infinitely scrolled table starts. Sent
            // only while appending; every other fetch has already cleared it,
            // because a cursor describes a position in a result the reader has
            // just changed.
            cursor: this.cursor || undefined,
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
        const body = this.root.querySelector('[data-dynamic-table-body]');
        if (!body) return;

        const columns = this.visibleColumns();
        const selectable = !!this.features.selection;
        const fragment = document.createDocumentFragment();

        const span = columns.length + (selectable ? 1 : 0) + (this.features.row_detail ? 1 : 0)
            + (this.boot.reorderable ? 1 : 0) + (this.features.pinned_rows ? 1 : 0)
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

        // The application's own empty state, and only where it belongs: "add
        // the first product" under a filter that matched nothing answers a
        // question the reader did not ask. Mirrors table.blade.php.
        const slot = !filtered && this.slots.empty
            ? el('div', { class: 'dynamic-table-slot', 'data-dynamic-table-slot': 'empty', html: this.slots.empty })
            : null;

        return el('div', { class: 'dynamic-table-empty-state', 'data-dynamic-table-empty': '' }, [
            el('p', { class: 'dynamic-table-empty-title', text: this.t(filtered ? 'empty_filtered' : 'empty') }),
            filtered ? el('p', { class: 'dynamic-table-empty-hint', text: this.t('empty_filtered_hint') }) : null,
            filtered
                ? el('button', {
                    type: 'button',
                    class: this.classes.button,
                    text: this.t('clear_filters'),
                    'data-dynamic-table-clear-filters': '',
                })
                : null,
            slot,
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

        const search = this.root.querySelector('[data-dynamic-table-search]');

        if (search) search.value = '';

        this.root.querySelectorAll('[data-dynamic-table-column-search]').forEach((input) => { input.value = ''; });

        this.refresh({ resetPage: true });
        this.emit('filters-cleared');
    }

    renderGroupRow(key, value, span) {
        const column = this.columns.find((candidate) => candidate.key === key);
        const totals = this.data.groupSummaries?.[groupKey(value)] ?? null;

        return el('tr', { class: `${this.classes.group} dynamic-table-group-row` }, [
            el('td', { colspan: span }, [
                el('span', { class: 'dynamic-table-group-label', text: `${column?.label ?? key}: ` }),
                el('strong', { text: value === null || value === '' ? '—' : String(value) }),
                totals ? this.renderGroupSummaries(totals) : null,
            ]),
        ]);
    }

    /**
     * A group heading's subtotals. Mirrors the same block in table.blade.php.
     *
     * The column label is repeated beside every number on purpose: three
     * figures on one line with only "Sum" and "Average" to tell them apart is
     * a puzzle, not a summary.
     */
    renderGroupSummaries(totals) {
        return el(
            'span',
            { class: 'dynamic-table-group-summaries' },
            this.columns
                .filter((column) => column.visible && totals[column.key] !== undefined)
                .map((column) => el('span', { class: 'dynamic-table-group-summary' }, [
                    el('span', {
                        class: 'dynamic-table-summary-label',
                        text: `${column.label} · ${this.t(`summary.${column.summary}`)}`,
                    }),
                    el('span', { class: 'dynamic-table-summary-value', text: totals[column.key] }),
                ])),
        );
    }

    renderRow(row, columns, selectable) {
        const selected = this.isSelected(row.id);

        const tr = el('tr', {
            class: [this.classes.row, selected ? this.classes.rowSelected : null],
            'data-dynamic-table-row': row.id,
            'data-trashed': row.trashed ? '' : null,
        });

        // Mirrors the same cell in table.blade.php.
        if (this.features.pinned_rows) {
            tr.append(
                el('td', { class: `${this.classes.cell} dynamic-table-pin-cell` }, [
                    el('button', {
                        type: 'button',
                        class: 'dynamic-table-pin',
                        'data-dynamic-table-pin': row.id,
                        'aria-pressed': 'false',
                        title: this.t('pin_row'),
                        text: '☆',
                    }),
                ]),
            );
        }

        // Mirrors the same cell in table.blade.php. The handle is hidden until
        // the reorder module says the current sort allows dragging.
        if (this.boot.reorderable) {
            tr.append(
                el('td', { class: `${this.classes.cell} dynamic-table-reorder-cell` }, [
                    // A button, not a decoration: it is focusable, it has a
                    // name, and Alt with an arrow key moves the row without a
                    // mouse. Mirrors table.blade.php.
                    el('button', {
                        type: 'button',
                        class: 'dynamic-table-reorder-handle',
                        'data-dynamic-table-reorder-handle': '',
                        'aria-label': this.t('reorder_row'),
                        title: this.t('reorder_row'),
                        hidden: true,
                        text: '≡',
                    }),
                ]),
            );
        }

        if (this.features.row_detail) {
            tr.append(
                el('td', { class: `${this.classes.cell} dynamic-table-expand-cell` }, [
                    el('button', {
                        type: 'button',
                        class: 'dynamic-table-expand',
                        'data-dynamic-table-detail': row.id,
                        'aria-expanded': 'false',
                        'aria-label': this.t('detail.toggle'),
                        text: '\u203a',
                    }),
                ]),
            );
        }

        if (selectable) {
            tr.append(
                el('td', { class: `${this.classes.cell} dynamic-table-select-cell` }, [
                    el('input', {
                        type: 'checkbox',
                        'data-dynamic-table-select': row.id,
                        'aria-label': this.t('select_row'),
                        checked: selected,
                    }),
                ]),
            );
        }

        for (const column of columns) {
            const td = el('td', {
                class: [this.classes.cell, `dynamic-table-align-${column.align || 'start'}`, column.class],
                'data-dynamic-table-cell': column.key,
                'data-label': column.label,
                'data-dynamic-table-editable': column.editable && this.features.inline_edit ? '' : null,
                'data-dynamic-table-sticky': (this.boot.sticky || []).includes(column.key) ? '' : null,
            });

            this.paintCell(td, column, row.c?.[column.key], row);
            tr.append(td);
        }

        const rowActions = this.boot.rowActions || [];

        if (rowActions.length) {
            const cell = el('td', { class: `${this.classes.cell} dynamic-table-row-actions-cell` });

            for (const action of rowActions) {
                // The server decided per record which actions apply; absent
                // means this row does not get that button.
                const applies = row.a?.[action.name];

                if (applies === undefined) continue;

                const shared = {
                    class: [
                        'dynamic-table-row-action',
                        action.destructive ? 'dynamic-table-row-action-danger' : null,
                        // Its own classes mean the package stops painting it.
                        action.class ? `dynamic-table-row-action-custom ${action.class}` : null,
                    ],
                    title: action.label,
                };

                // Mirrors partials/row-action.blade.php. The server normalised
                // the icon to safe HTML — an icon font element stays markup, a
                // glyph is already escaped — so it is inserted rather than
                // printed; the label is always plain text.
                const content = [
                    action.icon ? el('span', { class: 'dynamic-table-row-action-icon', 'aria-hidden': 'true', html: action.icon }) : null,
                    ! action.icon || action.showLabel ? el('span', { class: 'dynamic-table-row-action-label', text: action.label }) : null,
                ];

                cell.append(action.link
                    ? el('a', { ...shared, href: applies, target: action.target || null, rel: action.target ? 'noopener' : null }, content)
                    : el('button', { ...shared, type: 'button', 'data-dynamic-table-row-action': action.name }, content));
            }

            tr.append(cell);
        }

        return tr;
    }

    /**
     * The classes of one badge — mirrors Columns\Badge::classes().
     *
     * A theme that writes {tone} in its badge slot says where the tone goes;
     * every other theme gets the package modifier appended.
     */
    badgeClass(tone) {
        const base = this.classes.badge || 'dynamic-table-badge';
        const slug = String(tone || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');

        if (base.includes('{tone}')) return base.replace('{tone}', slug || 'neutral').trim();

        return slug ? `${base} dynamic-table-badge-${slug}` : base;
    }

    /** Mirrors resources/views/partials/cell.blade.php. */
    paintCell(td, column, value, row) {
        td.replaceChildren();

        if (value === null || value === undefined || value === '') {
            td.append(el('span', { class: 'dynamic-table-null', text: '—', 'aria-hidden': 'true' }));
            this.linkCell(td, column, row);

            return;
        }

        // row.h marks the cells whose render closure returned an Htmlable.
        if (column.raw || column.format === 'raw' || row?.h?.[column.key]) {
            td.innerHTML = value;
            this.linkCell(td, column, row);

            return;
        }

        switch (column.type) {
            case 'boolean':
                td.append(el('span', {
                    class: `dynamic-table-bool ${value ? 'dynamic-table-bool-true' : 'dynamic-table-bool-false'}`,
                    title: value ? this.t('yes') : this.t('no'),
                    text: value ? '✓' : '✕',
                }));
                break;
            case 'enum':
                td.append(el('span', { class: this.badgeClass(String(value)), text: String(value) }));
                break;
            case 'image':
                td.append(el('img', { src: value, alt: '', class: 'dynamic-table-thumb', loading: 'lazy' }));
                break;
            case 'url':
                td.append(el('a', {
                    href: value,
                    class: 'dynamic-table-link',
                    rel: 'noopener noreferrer',
                    target: '_blank',
                    text: String(value).length > 40 ? `${String(value).slice(0, 40)}…` : value,
                }));
                break;
            case 'email':
                td.append(el('a', { href: `mailto:${value}`, class: 'dynamic-table-link', text: value }));
                break;
            default:
                td.textContent = String(value);
        }

        this.linkCell(td, column, row);
    }

    /**
     * Wrap the first cell's content in the row's link.
     *
     * A real anchor rather than a click handler, and in the cell rather than
     * over the row: it is focusable, it is announced as a link, a middle-click
     * opens a tab, and "copy link address" works — none of which a handler
     * gives you. The rest of the row is handled by the click listener, which is
     * a convenience on top of this rather than the mechanism.
     *
     * Done here, at the end of painting, so a cell repainted after an inline
     * edit keeps its link instead of quietly losing it.
     */
    /**
     * A click on the body of a row follows that row's link.
     *
     * The first cell is already a real anchor, so this only covers the rest of
     * the row — and it stays out of the way of everything a row is also for.
     * Each of these exclusions is something that used to be a bug report
     * somewhere:
     *
     *  - anything already interactive: a link, a button, an input, a label;
     *  - the cells inline editing owns, so a double-click still opens an
     *    editor rather than leaving the page;
     *  - a click that ended a text selection, because selecting a cell's text
     *    and having the page navigate is maddening;
     *  - anything outside the table body — the toolbar, a panel, the footer.
     *
     * A modified click opens a tab instead, the way a link would.
     */
    followRow(event) {
        if (event.button !== undefined && event.button !== 0) return;

        const cell = event.target.closest?.('td');
        const tr = cell?.closest('[data-dynamic-table-row]');

        if (! tr || ! this.root.contains(tr)) return;

        if (event.target.closest('a, button, input, select, textarea, label, [contenteditable], [data-dynamic-table-row-action]')) return;

        if (this.features.inline_edit && cell.hasAttribute('data-dynamic-table-editable')) return;

        if (String(window.getSelection?.() ?? '').length > 0) return;

        const row = this.data.rows.find((candidate) => String(candidate.id) === String(tr.dataset.dynamicTableRow));

        if (! row?.u) return;

        event.preventDefault();

        if (event.metaKey || event.ctrlKey || event.shiftKey) {
            window.open(row.u, '_blank', 'noopener');

            return;
        }

        window.location.assign(row.u);
    }

    linkCell(td, column, row) {
        if (! row?.u || column.key !== this.visibleColumns()[0]?.key) return;

        const link = el('a', { class: 'dynamic-table-row-link', href: row.u });

        link.append(...td.childNodes);
        td.replaceChildren(link);
    }

    renderPagination() {
        const nav = this.root.querySelector('[data-dynamic-table-pagination]');
        if (!nav) return;

        const { page, lastPage, counted, hasMore } = this.data;
        nav.replaceChildren();

        // The arrows are glyphs, so their accessible name has to be a word:
        // "‹" announced literally tells a screen-reader user nothing.
        const button = (label, target, { disabled = false, current = false, name = null } = {}) =>
            el('button', {
                type: 'button',
                class: [this.classes.button, 'dynamic-table-page', current ? 'dynamic-table-page-current' : null],
                disabled,
                'aria-current': current ? 'page' : null,
                'aria-label': name ?? (typeof label === 'number' ? `${this.t('page')} ${label}` : label),
                text: String(label),
                onclick: () => this.goToPage(target),
            });

        const previous = (target, disabled) => button('‹', target, { disabled, name: this.t('previous') });
        const next = (target, disabled) => button('›', target, { disabled, name: this.t('next') });

        // Without a count there is no last page and no page list — only
        // whether another page exists. Showing invented numbers would be worse
        // than showing none.
        if (counted === false) {
            if (page <= 1 && !hasMore) return;

            nav.append(
                previous(page - 1, page <= 1),
                el('span', { class: 'dynamic-table-page-current-only', text: String(page) }),
                next(page + 1, !hasMore),
            );

            return;
        }

        if (!lastPage || lastPage <= 1) return;

        nav.append(previous(page - 1, page <= 1));

        for (const target of pageWindow(page, lastPage)) {
            if (target === '…') {
                nav.append(el('span', { class: 'dynamic-table-page-gap', text: '…' }));
            } else {
                nav.append(button(target, target, { current: target === page }));
            }
        }

        nav.append(next(page + 1, page >= lastPage));
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
        const link = this.root.querySelector('[data-dynamic-table-print]');

        if (! link) return;

        const url = new URL(link.href, window.location.origin);

        url.searchParams.set('table', this.key);
        url.searchParams.set('state', JSON.stringify(this.serializeState()));

        link.href = url.toString();
    }

    renderSummaryRow() {
        const foot = this.root.querySelector('[data-dynamic-table-summary-row]');

        if (! foot) return;

        const summaries = this.data.summaries || {};

        foot.hidden = Object.keys(summaries).length === 0;

        for (const cell of foot.querySelectorAll('[data-dynamic-table-summary]')) {
            const key = cell.getAttribute('data-dynamic-table-summary');
            const column = this.columns.find((candidate) => candidate.key === key);
            const value = summaries[key];

            cell.replaceChildren();

            if (value === undefined) continue;

            cell.append(
                el('span', { class: 'dynamic-table-summary-label', text: this.t(`summary.${column?.summary || 'sum'}`) }),
                el('span', { class: 'dynamic-table-summary-value', text: String(value) }),
            );
        }
    }

    /** The "Showing 11–20 of 100" line in the footer. */
    renderSummary() {
        const summary = this.root.querySelector('[data-dynamic-table-range]');

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

        this.root.querySelectorAll('[data-dynamic-table-column]').forEach((th) => {
            const on = filtered.has(th.getAttribute('data-dynamic-table-column'));

            th.toggleAttribute('data-dynamic-table-filtered', on);

            let marker = th.querySelector('.dynamic-table-filtered-icon');

            if (on && ! marker) {
                marker = el('span', { class: 'dynamic-table-filtered-icon', 'aria-hidden': 'true', text: '▼' });
                (th.querySelector('.dynamic-table-header-trigger') || th).append(marker);
            } else if (! on && marker) {
                marker.remove();
            }
        });
    }

    renderSortIndicators() {
        const sort = new Map((this.state.sort || []).map((entry) => [entry.field, entry.direction]));

        this.root.querySelectorAll('[data-dynamic-table-column]').forEach((th) => {
            const key = th.getAttribute('data-dynamic-table-column');
            const direction = sort.get(key);
            const icon = th.querySelector('.dynamic-table-sort-icon');

            if (icon) icon.textContent = direction ? (direction === 'asc' ? '▲' : '▼') : '';
            if (th.hasAttribute('aria-sort')) {
                th.setAttribute('aria-sort', direction ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
            }
        });
    }

    renderDebug(debug) {
        const panel = this.root.querySelector('[data-dynamic-table-panel]');
        if (!panel || !debug) return;

        panel.textContent = `${debug.ms} ms · ${debug.memory} MB · eager: ${(debug.relations || []).join(', ') || 'none'}`;
    }

    /** Rebuild the header when the column set or order changed. */
    renderHeader() {
        const headRow = this.root.querySelector('[data-dynamic-table-table] thead tr');
        if (!headRow) return;

        const columns = this.visibleColumns();
        const selectable = !!this.features.selection;
        const cells = [];

        // The expander and the row buttons are cells of the header too. Leaving
        // them out here would shift every column one place the first time the
        // header is repainted.
        if (this.features.row_detail) {
            cells.push(el('th', { class: `${this.classes.th} dynamic-table-expand-cell`, scope: 'col' }, [
                el('span', { class: 'dynamic-table-visually-hidden', text: this.t('detail.title') }),
            ]));
        }

        if (selectable) {
            cells.push(el('th', { class: `${this.classes.th} dynamic-table-select-cell`, scope: 'col' }, [
                el('input', { type: 'checkbox', 'data-dynamic-table-select-all': '', 'aria-label': this.t('select_all') }),
            ]));
        }

        const headerMenu = (this.boot.modules || []).includes('header-menu');

        for (const column of columns) {
            const width = this.state.widths?.[column.key] || column.width;

            const th = el('th', {
                class: [this.classes.th, column.sortable && ! headerMenu ? this.classes.thSortable : null, `dynamic-table-align-${column.align || 'start'}`],
                scope: 'col',
                'data-dynamic-table-column': column.key,
                style: width ? `width:${width}px` : null,
                'aria-sort': column.sortable ? 'none' : null,
            });

            // With the header menu on, the header opens the menu rather than
            // sorting: both sort directions are in that menu, and a header that
            // does both makes one of them an accident. Mirrors the template.
            if (headerMenu) {
                th.append(el('button', {
                    type: 'button',
                    class: 'dynamic-table-header-trigger',
                    'data-dynamic-table-header-menu': column.key,
                    'aria-haspopup': 'menu',
                    'aria-expanded': 'false',
                    'aria-label': this.t('header.menu', { column: column.label }),
                }, [
                    el('span', { text: column.label }),
                    el('span', { class: 'dynamic-table-sort-icon', 'aria-hidden': 'true' }),
                    el('span', { class: 'dynamic-table-header-cog', 'aria-hidden': 'true', text: '⚙' }),
                ]));
            } else if (column.sortable) {
                th.append(el('button', { type: 'button', class: 'dynamic-table-sort', 'data-dynamic-table-sort': column.key }, [
                    el('span', { text: column.label }),
                    el('span', { class: 'dynamic-table-sort-icon', 'aria-hidden': 'true' }),
                ]));
            } else {
                th.append(el('span', { text: column.label }));
            }

            if (this.features.column_resize) {
                th.append(el('span', {
                    class: this.classes.resizer,
                    'data-dynamic-table-resizer': column.key,
                    role: 'separator',
                    'aria-orientation': 'vertical',
                }));
            }

            cells.push(th);
        }

        if ((this.boot.rowActions || []).length) {
            cells.push(el('th', { class: `${this.classes.th} dynamic-table-row-actions-cell`, scope: 'col' }, [
                el('span', { class: 'dynamic-table-visually-hidden', text: this.t('actions.title') }),
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
        const row = this.root.querySelector('[data-dynamic-table-table] [data-dynamic-table-search-row]');
        if (!row) return;

        const cells = [];

        if (this.features.row_detail) cells.push(el('th', { class: `${this.classes.th} dynamic-table-expand-cell` }));
        if (this.features.selection) cells.push(el('th', { class: `${this.classes.th} dynamic-table-select-cell` }));

        for (const column of this.visibleColumns()) {
            const cell = el('th', { class: this.classes.th, 'data-dynamic-table-search-cell': column.key });

            if (column.filterable) {
                cell.append(el('input', {
                    type: 'text',
                    class: this.classes.input || '',
                    'data-dynamic-table-column-search': column.key,
                    value: this.state.columnSearch?.[column.key] || '',
                    'aria-label': this.t('search_column', { column: column.label }),
                }));
            }

            cells.push(cell);
        }

        if ((this.boot.rowActions || []).length) cells.push(el('th', { class: `${this.classes.th} dynamic-table-row-actions-cell` }));

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
        const element = this.root.querySelector('[data-dynamic-table-table]');
        const headRow = element?.querySelector('thead tr');

        if (!element || !headRow) return;

        element.style.setProperty('--dynamic-table-head-offset', `${Math.round(headRow.getBoundingClientRect().height)}px`);
    }

    /**
     * Once a column has been given a width, the table stops sharing the
     * container out between columns and honours the widths it was given: fixed
     * layout, sized to its content, scrolling sideways when the total no longer
     * fits. Without this, widening one column can only come out of another.
     */
    syncSizedLayout() {
        const element = this.root.querySelector('[data-dynamic-table-table]');
        if (!element) return;

        /*
         * Both kinds of width count: the ones the reader dragged and the ones
         * the column declared. A declared width is rendered as an inline style
         * on the header, and it needs fixed layout just as much — auto layout
         * would refuse to take the column under the width of its own label.
         */
        const widths = { ...(this.state.widths || {}) };

        element.querySelectorAll('thead th[data-dynamic-table-column]').forEach((th) => {
            const key = th.getAttribute('data-dynamic-table-column');
            const declared = parseInt(th.style.width, 10);

            if (!widths[key] && Number.isFinite(declared)) widths[key] = declared;
        });

        element.classList.toggle('dynamic-table-sized', Object.keys(widths).length > 0);

        // The widths go back onto the headers, so a re-render that arrives
        // without them cannot leave the state and the table disagreeing.
        element.querySelectorAll('thead th[data-dynamic-table-column]').forEach((th) => {
            const width = widths[th.getAttribute('data-dynamic-table-column')];

            if (width) th.style.width = `${width}px`;
        });

        // A column narrowed to the width of "$2" cannot also carry a cell's
        // worth of padding — border-box means the padding would eat the whole
        // column and leave no room even for the ellipsis.
        element.querySelectorAll('.dynamic-table-narrow').forEach((cell) => cell.classList.remove('dynamic-table-narrow'));

        for (const [key, width] of Object.entries(widths)) {
            if (width >= 64) continue;

            const escaped = CSS.escape(key);

            element.querySelectorAll(
                `th[data-dynamic-table-column="${escaped}"], th[data-dynamic-table-search-cell="${escaped}"], td[data-dynamic-table-cell="${escaped}"]`,
            ).forEach((cell) => cell.classList.add('dynamic-table-narrow'));
        }
    }

    /**
     * @param {string|null} label what the table is busy doing, when it is
     *        something other than fetching rows — running a bulk action, say.
     */
    setLoading(loading, label = null) {
        const indicator = this.root.querySelector('[data-dynamic-table-loading]');

        if (indicator) {
            indicator.hidden = !loading;

            const text = indicator.querySelector('span:last-child');

            if (text) text.textContent = label ?? this.t('loading');
        }

        this.root.classList.toggle('dynamic-table-is-loading', loading);
        this.root.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    alert(message, kind = 'info', { timeout = 6000, action = null } = {}) {
        const host = this.root.querySelector('[data-dynamic-table-alerts]');
        if (!host) return;

        const node = el('div', { class: `dynamic-table-alert dynamic-table-alert-${kind}`, role: kind === 'error' ? 'alert' : 'status' }, [
            el('span', { text: message }),
            action ? el('button', { type: 'button', class: this.classes.button, text: action.label, onclick: action.handler }) : null,
            el('button', { type: 'button', class: 'dynamic-table-alert-close', 'aria-label': this.t('close'), text: '×', onclick: () => node.remove() }),
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
        const search = this.root.querySelector('[data-dynamic-table-search]');

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
            const input = event.target.closest?.('[data-dynamic-table-column-search]');
            if (!input || !this.root.contains(input)) return;

            const key = input.getAttribute('data-dynamic-table-column-search');
            this.state.columnSearch = { ...(this.state.columnSearch || {}) };

            if (input.value.trim() === '') delete this.state.columnSearch[key];
            else this.state.columnSearch[key] = input.value.trim();

            columnSearch();
        });

        const perPage = this.root.querySelector('[data-dynamic-table-per-page]');

        if (perPage) {
            perPage.addEventListener('change', () => {
                this.state.perPage = Number(perPage.value);
                // Changing the page size also re-pages, so the same rule applies.
                this.scrollIntoView();
                this.refresh({ resetPage: true });
            });
        }

        // Header interactions are delegated so a re-rendered header keeps working.
        if (this.boot.rowClick === 'single' || this.boot.rowClick === 'double') {
            this.root.addEventListener(this.boot.rowClick === 'double' ? 'dblclick' : 'click', (event) => {
                this.followRow(event);
            });
        }

        this.root.addEventListener('click', (event) => {
            const sortButton = event.target.closest('[data-dynamic-table-sort]');

            if (sortButton && this.root.contains(sortButton)) {
                this.toggleSort(sortButton.getAttribute('data-dynamic-table-sort'), event.shiftKey);

                return;
            }

            const print = event.target.closest('[data-dynamic-table-print]');

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
                window.open(print.href, `dynamic-table-print-${this.key}`);

                return;
            }

            if (event.target.closest('[data-dynamic-table-clear-filters]')) {
                event.preventDefault();
                this.clearFilters();

                return;
            }

            const opener = event.target.closest('[data-dynamic-table-open]');

            if (opener && this.root.contains(opener)) {
                event.preventDefault();
                this.open(opener.getAttribute('data-dynamic-table-open'), opener);
            }
        });

        this.root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.emit('escape', event);
        });

        this.bindParams();

        // A header row that wraps at one width does not wrap at another, and
        // the row below it sticks to whatever it measures now.
        window.addEventListener('resize', debounce(() => this.syncHeaderOffset(), 150), { signal: this.teardown.signal });

        window.addEventListener('popstate', () => {
            if (this.features.url_state) this.readUrl();
        }, { signal: this.teardown.signal });
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

        // Every one of these is on the document rather than the table, so every
        // one of them outlives the table unless destroy() can take it back —
        // which is what the shared abort signal is for.
        const signal = this.teardown.signal;

        scope.addEventListener('change', (event) => {
            if (event.target.closest?.(this.paramSelector())) apply();
        }, { signal });

        // Typing is debounced; a date or select fires "change" and applies at once.
        scope.addEventListener('input', (event) => {
            const control = event.target.closest?.(this.paramSelector());

            if (control && ['text', 'search', 'number'].includes(control.type)) applyLater();
        }, { signal });

        scope.addEventListener('submit', (event) => {
            const form = event.target.closest?.(`form[data-dynamic-table-params="${CSS.escape(this.key)}"]`);

            if (form) {
                event.preventDefault();
                apply();
            }
        }, { signal });

        scope.addEventListener('click', (event) => {
            const reset = event.target.closest?.(`[data-dynamic-table-params-reset="${CSS.escape(this.key)}"]`);

            if (reset) {
                event.preventDefault();
                this.resetParams();
            }
        }, { signal });
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
        // Announced before anything is let go of, so a listener can still read
        // the table it is being told about.
        this.emit('destroyed', this);

        this.observer?.disconnect();
        this.pending?.abort();
        this.teardown.abort();
        this.listeners.clear();
        this.modules.clear();
        this.root.removeAttribute('data-dynamic-table-mounted');
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
        const sentinel = this.root.querySelector('[data-dynamic-table-sentinel]');

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
        // A cursor-paged result is counted and still has no last page to
        // compare against — "is there another one" is the only question it
        // answers, and the only one infinite scrolling needs to ask.
        if (this.data.counted === false || this.data.nextCursor !== undefined) {
            return !! this.data.hasMore;
        }

        return this.state.page < (this.data.lastPage || 1);
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
     *     .dynamic-table { scroll-margin-top: 5rem; }
     */
    scrollIntoView() {
        if (this.boot.scrollOnPage === false) return;

        const scroller = this.root.querySelector('[data-dynamic-table-scroller]');

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

        const search = this.root.querySelector('[data-dynamic-table-search]');
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
        return `[data-dynamic-table-param][data-dynamic-table-table="${CSS.escape(this.key)}"], [data-dynamic-table-params="${CSS.escape(this.key)}"] [data-dynamic-table-param]`;
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
            const name = input.getAttribute('data-dynamic-table-param');
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
        if (!moduleFor || this.opening || this.root.dataset.dynamicTableOpening) return;

        this.opening = panel;
        this.root.dataset.dynamicTableOpening = panel;

        try {
            const api = await this.load(moduleFor);
            await api?.open?.(panel, trigger);
        } catch (error) {
            this.alert(this.t('errors.generic'), 'error');
            console.error('[DynamicTable] could not open panel', panel, error);
        } finally {
            this.opening = null;
            delete this.root.dataset.dynamicTableOpening;
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
    if (root.dataset.dynamicTableMounted === 'true') return null;

    const script = root.querySelector('script[data-dynamic-table-boot]');
    if (!script) return null;

    let boot;

    try {
        boot = JSON.parse(script.textContent);
    } catch (error) {
        console.error('[DynamicTable] invalid boot payload', error);

        return null;
    }

    root.dataset.dynamicTableMounted = 'true';

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

    /*
     * Everything except the panels is warmed now.
     *
     * Stated as "which modules are lazy" rather than "which are eager", which
     * is the safer way round: a panel is lazy because nothing of it is on the
     * page until it is opened, and that list changes rarely. The other way
     * round, every new module has to be remembered here — and a module that is
     * forgotten does not fail, it just silently never binds. The row-reorder
     * grips and the pin stars were rendered and dead for exactly that reason.
     */
    const lazy = ['filters', 'views', 'columns', 'transfer'];

    for (const name of boot.modules || []) {
        if (! lazy.includes(name)) table.load(name);
    }

    // Resizing lives in the columns module but is not a panel: its handles are
    // in the header from first paint, so the module has to be there before the
    // first drag rather than when the picker is opened.
    if (boot.features?.column_resize) table.load('columns');

    return table;
}

export function boot(scope = document) {
    sweep();
    scope.querySelectorAll('[data-dynamic-table]').forEach(mount);
}

/**
 * Let go of every table whose element has left the page.
 *
 * mount() already replaces a table whose root was swapped for a new one of the
 * same key, but a navigation that removes a table without putting another in
 * its place leaves nothing to trigger that — and the table would keep its
 * document listeners and its observer for the life of the tab.
 */
export function sweep() {
    for (const table of new Set(registry.values())) {
        if (! table.root.isConnected) table.destroy();
    }
}

export function find(key) {
    return registry.get(key) || null;
}

if (typeof window !== 'undefined') {
    window.DynamicTable = { boot, mount, find, sweep, el, debounce };

    // Global, so a second copy of this module cannot register a second set of
    // navigation listeners and boot everything again.
    if (! window.__dynamicTableStarted) {
        window.__dynamicTableStarted = true;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => boot());
        } else {
            boot();
        }

        /*
         * Every way a page can be replaced without being loaded.
         *
         * All of them do the same two things — let go of the tables whose DOM
         * has gone, mount the ones that have arrived — so all of them call the
         * same function. Listening for an event a page never fires costs
         * nothing, which is why they are all here rather than behind a
         * detection of which framework is present.
         *
         * Inertia is listened for twice on purpose: "inertia:success" fires
         * for a visit that swapped the page, and "inertia:navigate" for a
         * history move between pages already rendered.
         */
        for (const event of ['livewire:navigated', 'livewire:load', 'turbo:load', 'inertia:success', 'inertia:navigate']) {
            document.addEventListener(event, () => boot());
        }

        // A Livewire component that re-renders morphs its own DOM without any
        // navigation, so the table's element may be replaced under it. The hook
        // exists only when Livewire does.
        document.addEventListener('livewire:init', () => {
            window.Livewire?.hook?.('morphed', () => boot());
        });
    }
}

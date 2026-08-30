/**
 * Column picker, drag-and-drop reordering and resizing.
 *
 * Modelled on the Dynamics 365 "Edit columns" panel: the list is the columns
 * the view *has*, in the order it has them, and "Add column" opens the whole
 * field catalogue — the entity's own fields and those of its lookups — rather
 * than only the columns the developer happened to declare.
 *
 * That catalogue is the same one the filter builder uses, fetched lazily and
 * cached, so opening the picker on a plain table costs nothing until it is
 * opened. The server rebuilds every key against the same metadata, so a column
 * added here is a column the table could always have shown.
 *
 * Visibility and order live in table state, so whatever is arranged here is
 * exactly what a saved view stores and what an export produces.
 */

import { el } from './dom.js';
import { dialog } from './ui.js';

/** A glyph per field type, as Dynamics marks its column list. */
const ICONS = {
    string: 'Abc',
    text: 'Abc',
    email: '@',
    url: '↗',
    integer: '123',
    decimal: '1.2',
    boolean: '✓',
    date: '▤',
    datetime: '▤',
    time: '◷',
    enum: '☰',
    json: '{}',
    image: '▣',
};

export default function install(table) {
    const defaults = table.columns.filter((column) => column.visible).map((column) => column.key);

    let catalogue = null;

    /*
     * Definitions for columns added in this panel.
     *
     * The catalogue already describes the field — label, type, path — so the
     * list and the header can show it straight away instead of a raw key while
     * the request is in flight. The server sends its own definition back with
     * the rows, which then replaces this one.
     */
    const drafted = new Map();

    if (table.features.column_resizing) installResizing(table);

    /** Everything the table knows about a key, declared or added earlier. */
    function definitionFor(key) {
        return table.columns.find((column) => column.key === key) || drafted.get(key);
    }

    async function fields() {
        // Cached as a promise so two quick opens share one request — but a
        // failed request is forgotten, or the panel would keep replaying the
        // same error for the rest of the page's life.
        catalogue ??= table.post(table.endpoints.fields)
            .then((response) => response.groups || [])
            .catch((error) => {
                catalogue = null;

                throw error;
            });

        return catalogue;
    }

    function icon(type) {
        return el('span', { class: 'dt-column-icon', 'aria-hidden': 'true', text: ICONS[type] || 'Abc' });
    }

    /* ------------------------------------------------------------------ */
    /* The chosen columns                                                  */
    /* ------------------------------------------------------------------ */

    function renderList(working, repaint) {
        const list = el('ul', { class: 'dt-column-list', role: 'list' });

        working.forEach((key, index) => {
            const column = definitionFor(key) || { key, label: key, type: 'string' };

            const item = el('li', {
                class: 'dt-column-item',
                draggable: table.features.column_reordering ? 'true' : null,
                'data-key': key,
                'data-index': index,
            }, [
                table.features.column_reordering
                    ? el('span', { class: 'dt-drag-handle', 'aria-hidden': 'true', text: '⠿' })
                    : null,
                icon(column.type),
                el('span', { class: 'dt-column-name', text: column.label }),
                column.relation ? el('span', { class: 'dt-column-hint', text: column.relation }) : null,
                el('button', {
                    type: 'button',
                    class: 'dt-column-remove',
                    'aria-label': table.t('columns_panel.remove', { column: column.label }),
                    text: '✕',
                    onclick: () => {
                        // A table with no columns at all is not a state worth
                        // being able to reach.
                        if (working.length <= 1) return;

                        working.splice(working.indexOf(key), 1);
                        repaint();
                    },
                }),
            ]);

            if (table.features.column_reordering) makeDraggable(item, key, working, repaint);

            list.append(item);
        });

        return list;
    }

    function makeDraggable(item, key, working, repaint) {
        item.addEventListener('dragstart', (event) => {
            event.dataTransfer.setData('text/plain', key);
            item.classList.add('dt-dragging');
        });

        item.addEventListener('dragend', () => item.classList.remove('dt-dragging'));

        item.addEventListener('dragover', (event) => {
            event.preventDefault();
            item.classList.add('dt-drop-target');
        });

        item.addEventListener('dragleave', () => item.classList.remove('dt-drop-target'));

        item.addEventListener('drop', (event) => {
            event.preventDefault();
            item.classList.remove('dt-drop-target');

            const dragged = event.dataTransfer.getData('text/plain');
            if (!dragged || dragged === key) return;

            const from = working.indexOf(dragged);
            const to = working.indexOf(key);

            if (from === -1) {
                working.splice(to === -1 ? working.length : to, 0, dragged);
            } else {
                working.splice(from, 1);
                working.splice(to === -1 ? working.length : to, 0, dragged);
            }

            repaint();
        });

        // Keyboard reordering keeps this usable without a mouse.
        item.addEventListener('keydown', (event) => {
            if (!event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;

            event.preventDefault();

            const at = working.indexOf(key);
            if (at === -1) return;

            const target = event.key === 'ArrowUp' ? at - 1 : at + 1;
            if (target < 0 || target >= working.length) return;

            working.splice(at, 1);
            working.splice(target, 0, key);
            repaint();
        });

        item.tabIndex = 0;
    }

    /* ------------------------------------------------------------------ */
    /* Adding a column                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The catalogue, grouped by entity, minus what is already chosen.
     *
     * Rendered into the *same* panel rather than a second dialog on top: a
     * table shows one dialog at a time, so a nested one closes the picker it
     * was opened from — taking the chosen columns and the Apply button with it.
     * Dynamics switches the panel's contents for the same reason.
     *
     * Grouping matters here: "Name" on the order and "Name" on the customer are
     * different columns, and a flat list of both is a guessing game.
     */
    function renderCatalogue(working, back) {
        const results = el('div', { class: 'dt-column-add-results' });

        const search = el('input', {
            type: 'search',
            class: table.classes.input,
            placeholder: table.t('columns_panel.search'),
            oninput: () => paint(search.value.trim().toLowerCase()),
        });

        let groups = [];

        const paint = (term = '') => {
            const fragment = document.createDocumentFragment();
            let shown = 0;

            for (const group of groups) {
                const matches = (group.fields || []).filter((field) => {
                    const key = String(field.path).replace(/\./g, '__');

                    if (working.includes(key)) return false;

                    return term === ''
                        || field.label.toLowerCase().includes(term)
                        || String(field.path).toLowerCase().includes(term);
                });

                if (!matches.length) continue;

                shown += matches.length;

                fragment.append(el('p', { class: 'dt-column-group', text: group.label }));

                for (const field of matches) {
                    const key = String(field.path).replace(/\./g, '__');

                    fragment.append(el('button', {
                        type: 'button',
                        class: 'dt-column-add-item',
                        onclick: () => {
                            /*
                             * The catalogue already describes the field, so the
                             * list and the header can show a real label at once
                             * rather than a raw key while the request is in
                             * flight. The server sends its own definition back
                             * with the rows, which then replaces this one.
                             */
                            drafted.set(key, {
                                key,
                                label: field.label,
                                type: field.type,
                                path: field.path,
                                sortable: field.sortable !== false,
                                filterable: field.filterable !== false,
                                align: field.type === 'integer' || field.type === 'decimal' ? 'end' : 'start',
                                relation: group.key || null,
                                visible: true,
                                added: true,
                            });

                            working.push(key);

                            // Straight back to the list, where the new column is
                            // now the last row — the answer to "did that work?"
                            back();
                        },
                    }, [
                        icon(field.type),
                        el('span', { class: 'dt-column-name', text: field.label }),
                    ]));
                }
            }

            if (!shown) {
                fragment.append(el('p', { class: 'dt-hint', text: table.t('columns_panel.none_left') }));
            }

            results.replaceChildren(fragment);
        };

        results.append(el('p', { class: 'dt-hint', text: table.t('loading') }));

        fields()
            .then((loaded) => {
                groups = loaded;
                paint();
                search.focus();
            })
            .catch((error) => {
                results.replaceChildren(el('p', { class: 'dt-form-errors', text: error.message }));
            });

        return el('div', { class: 'dt-column-add' }, [search, results]);
    }

    return {
        open() {
            const working = [...table.state.columns];
            const body = el('div', { class: 'dt-column-picker' });
            const foot = el('div', { class: 'dt-modal-actions' });

            // Which of the panel's two panes is showing.
            let adding = false;

            const show = (pane) => {
                adding = pane === 'add';
                repaint();
            };

            const repaint = () => {
                if (adding) {
                    body.replaceChildren(renderCatalogue(working, () => show('list')));

                    foot.replaceChildren(el('button', {
                        type: 'button',
                        class: table.classes.button,
                        text: table.t('back'),
                        onclick: () => show('list'),
                    }));

                    return;
                }

                body.replaceChildren(
                    el('div', { class: 'dt-column-actions' }, [
                        el('button', { type: 'button', class: 'dt-link-button', onclick: () => show('add') }, [
                            el('span', { 'aria-hidden': 'true', text: '＋' }),
                            el('span', { text: table.t('columns_panel.add') }),
                        ]),
                        el('button', {
                            type: 'button',
                            class: 'dt-link-button',
                            onclick: () => {
                                working.length = 0;
                                working.push(...defaults);
                                repaint();
                            },
                        }, [
                            el('span', { 'aria-hidden': 'true', text: '↺' }),
                            el('span', { text: table.t('columns_panel.reset') }),
                        ]),
                    ]),
                    table.features.column_reordering
                        ? el('p', { class: 'dt-hint', text: table.t('columns_panel.reorder_hint') })
                        : null,
                    renderList(working, repaint),
                );

                foot.replaceChildren(
                    el('button', {
                        type: 'button',
                        class: table.classes.button,
                        text: table.t('cancel'),
                        onclick: () => instance.close(),
                    }),
                    el('button', {
                        type: 'button',
                        class: table.classes.buttonPrimary,
                        text: table.t('apply'),
                        onclick: () => {
                            instance.close();

                            const keys = working.length ? [...working] : [...defaults];

                            // Definitions for anything added here, so the header
                            // can be drawn before the server answers.
                            table.setColumns(keys, {
                                definitions: keys.map((key) => drafted.get(key)).filter(Boolean),
                            });
                        },
                    }),
                );
            };

            repaint();

            const instance = dialog(table, {
                title: table.t('columns_panel.title'),
                width: '28rem',
                body,
                footer: foot,
            });
        },
    };
}


/**
 * Dragging a column edge.
 *
 * The first drag freezes every column at the width it currently has and puts
 * the table into fixed layout, so widening one column widens the table and
 * scrolls, instead of being taken out of its neighbour — a neighbour that
 * shrinks is a neighbour whose content disappears, which is not what anyone
 * dragging an edge is asking for.
 */
function installResizing(table) {
    let active = null;

    /*
     * The floor is the handle, not the content.
     *
     * A column holding "$2" has no business being 60px wide, so the only lower
     * bound is the one that keeps the column grabbable again afterwards. What
     * no longer fits is ellipsised, not accommodated.
     */
    const MIN_WIDTH = 24;

    const element = () => table.root.querySelector('[data-dt-table]');

    /** Pin every column to what it measures now, once, before the first drag. */
    const freeze = () => {
        const node = element();
        if (!node) return {};

        const widths = { ...table.state.widths };

        node.querySelectorAll('thead th').forEach((th) => {
            const key = th.getAttribute('data-dt-column');
            const width = Math.round(th.getBoundingClientRect().width);

            // Cells that are not columns of data are pinned too — otherwise a
            // fixed layout would re-share them out — but they are not state:
            // nobody sized them, and their content decides on the next paint.
            if (!key) {
                if (width > 0) th.style.width = `${width}px`;

                return;
            }

            if (!widths[key] && width > 0) widths[key] = width;
            th.style.width = `${widths[key]}px`;
        });

        node.classList.add('dt-sized');

        return widths;
    };

    const onMove = (event) => {
        if (!active) return;

        const delta = (table.boot.direction === 'rtl' ? -1 : 1) * (event.clientX - active.startX);
        const width = Math.max(MIN_WIDTH, Math.round(active.startWidth + delta));

        active.th.style.width = `${width}px`;
        active.width = width;
    };

    const onUp = () => {
        if (!active) return;

        table.state.widths = { ...active.widths, [active.key]: active.width };
        document.removeEventListener('pointermove', onMove);
        document.removeEventListener('pointerup', onUp);
        table.root.classList.remove('dt-resizing');
        table.emit('resized', { key: active.key, width: active.width });
        active = null;
    };

    table.root.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-dt-resizer]');
        if (!handle) return;

        const th = handle.closest('th');
        if (!th) return;

        event.preventDefault();

        const widths = freeze();
        const measured = Math.round(th.getBoundingClientRect().width);

        active = {
            key: handle.getAttribute('data-dt-resizer'),
            th,
            widths,
            startX: event.clientX,
            startWidth: measured,
            width: measured,
        };

        table.root.classList.add('dt-resizing');
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
    });
}

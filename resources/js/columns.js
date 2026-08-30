/**
 * Column picker, drag-and-drop reordering and resizing.
 *
 * Visibility and order live in table state, so whatever the user arranges here
 * is exactly what a saved view stores and what an export produces.
 */

import { el } from './dom.js';
import { dialog } from './ui.js';

export default function install(table) {
    const defaults = table.columns.filter((column) => column.visible).map((column) => column.key);

    if (table.features.column_resizing) installResizing(table);

    function renderList(working, repaint) {
        const list = el('ul', { class: 'dt-column-list', role: 'list' });
        const byKey = new Map(table.columns.map((column) => [column.key, column]));

        const ordered = [
            ...working.filter((key) => byKey.has(key)),
            ...table.columns.map((column) => column.key).filter((key) => !working.includes(key)),
        ];

        ordered.forEach((key, index) => {
            const column = byKey.get(key);
            const checked = working.includes(key);

            const item = el('li', {
                class: 'dt-column-item',
                draggable: table.features.column_reordering ? 'true' : null,
                'data-key': key,
                'data-index': index,
            }, [
                table.features.column_reordering
                    ? el('span', { class: 'dt-drag-handle', 'aria-hidden': 'true', text: '⠿' })
                    : null,
                el('label', { class: 'dt-column-label' }, [
                    el('input', {
                        type: 'checkbox',
                        checked,
                        onchange: (event) => {
                            if (event.target.checked) {
                                if (!working.includes(key)) working.push(key);
                            } else {
                                const at = working.indexOf(key);
                                if (at > -1) working.splice(at, 1);
                            }
                        },
                    }),
                    el('span', { text: column.label }),
                ]),
                column.relation ? el('span', { class: 'dt-column-hint', text: column.relation }) : null,
            ]);

            if (table.features.column_reordering) {
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

            list.append(item);
        });

        return list;
    }

    return {
        open() {
            const working = [...table.state.columns];
            const container = el('div', { class: 'dt-column-picker' });

            const repaint = () => {
                container.replaceChildren(
                    el('p', { class: 'dt-hint', text: table.features.column_reordering ? table.t('columns_panel.reorder_hint') : '' }),
                    renderList(working, repaint),
                );
            };

            repaint();

            const instance = dialog(table, {
                title: table.t('columns_panel.title'),
                width: '28rem',
                body: container,
                footer: el('div', { class: 'dt-modal-actions' }, [
                    el('button', {
                        type: 'button',
                        class: table.classes.button,
                        text: table.t('columns_panel.reset'),
                        onclick: () => {
                            working.length = 0;
                            working.push(...defaults);
                            repaint();
                        },
                    }),
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
                            table.setColumns(working.length ? [...working] : [...defaults]);
                        },
                    }),
                ]),
            });
        },
    };
}

function installResizing(table) {
    let active = null;

    const onMove = (event) => {
        if (!active) return;

        const delta = (table.boot.direction === 'rtl' ? -1 : 1) * (event.clientX - active.startX);
        const width = Math.max(60, active.startWidth + delta);

        active.th.style.width = `${width}px`;
        active.width = width;
    };

    const onUp = () => {
        if (!active) return;

        table.state.widths = { ...table.state.widths, [active.key]: active.width };
        document.removeEventListener('pointermove', onMove);
        document.removeEventListener('pointerup', onUp);
        table.root.classList.remove('dt-resizing');
        active = null;
    };

    table.root.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-dt-resizer]');
        if (!handle) return;

        const th = handle.closest('th');
        if (!th) return;

        event.preventDefault();

        active = {
            key: handle.getAttribute('data-dt-resizer'),
            th,
            startX: event.clientX,
            startWidth: th.getBoundingClientRect().width,
            width: th.getBoundingClientRect().width,
        };

        table.root.classList.add('dt-resizing');
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
    });
}

/**
 * The column header menu, modelled on Dynamics 365 grids.
 *
 * Clicking the chevron in a header offers the things people actually want from
 * a header: sort either way, group by this column, filter on it, set its width,
 * move it one place, or hide it. Every item maps onto table state that already
 * exists, so whatever the user does here is what a saved view stores and what
 * an export produces.
 *
 * Only the items whose features are enabled are shown — the menu never offers
 * an action the table cannot perform.
 */

import { el } from './dom.js';
import { dialog, menu } from './ui.js';

export default function install(table) {
    const byKey = () => new Map(table.columns.map((column) => [column.key, column]));

    /** Dynamics labels its sort directions by type: A→Z, 1→9, oldest→newest. */
    function sortLabels(column) {
        const type = column?.type;

        if (type === 'integer' || type === 'decimal') {
            return [table.t('header.sort_asc_number'), table.t('header.sort_desc_number')];
        }

        if (type === 'date' || type === 'datetime' || type === 'time') {
            return [table.t('header.sort_asc_date'), table.t('header.sort_desc_date')];
        }

        return [table.t('header.sort_asc_text'), table.t('header.sort_desc_text')];
    }

    function sortBy(key, direction) {
        table.state.sort = [{ field: key, direction }];
        table.refresh({ resetPage: true });
    }

    function move(key, delta) {
        const order = [...table.state.columns];
        const at = order.indexOf(key);
        const target = at + delta;

        if (at === -1 || target < 0 || target >= order.length) return;

        order.splice(at, 1);
        order.splice(target, 0, key);

        table.setColumns(order);
    }

    function hide(key) {
        const remaining = table.state.columns.filter((candidate) => candidate !== key);

        // Refuse to leave a table with no columns at all.
        if (! remaining.length) return;

        table.setColumns(remaining);
    }

    function widthDialog(column) {
        const th = table.root.querySelector(`[data-dynamic-table-column="${CSS.escape(column.key)}"]`);
        const currentWidth = table.state.widths?.[column.key] || Math.round(th?.getBoundingClientRect().width || 150);

        const input = el('input', {
            type: 'number',
            min: '24',
            max: '1200',
            step: '10',
            class: table.classes.input,
            value: String(currentWidth),
        });

        const instance = dialog(table, {
            title: table.t('header.width'),
            width: '20rem',
            body: el('div', { class: 'dynamic-table-form' }, [
                el('label', { class: 'dynamic-table-field' }, [
                    el('span', { class: 'dynamic-table-field-label', text: column.label }),
                    input,
                ]),
                el('p', { class: 'dynamic-table-hint', text: table.t('header.width_hint') }),
            ]),
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                el('button', {
                    type: 'button',
                    class: table.classes.button,
                    text: table.t('reset'),
                    onclick: () => {
                        const widths = { ...table.state.widths };
                        delete widths[column.key];
                        table.state.widths = widths;
                        if (th) th.style.width = '';
                        table.syncSizedLayout();
                        instance.close();
                    },
                }),
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                el('button', {
                    type: 'button',
                    class: table.classes.buttonPrimary,
                    text: table.t('apply'),
                    onclick: () => {
                        const width = Math.max(24, Math.min(1200, Number(input.value) || currentWidth));

                        table.state.widths = { ...table.state.widths, [column.key]: width };
                        if (th) th.style.width = `${width}px`;
                        table.syncSizedLayout();
                        instance.close();
                    },
                }),
            ]),
        });
    }

    async function open(key, trigger) {
        const column = byKey().get(key);

        if (! column) return;

        const features = table.features;
        const rtl = table.boot.direction === 'rtl';
        const order = table.state.columns;
        const at = order.indexOf(key);
        const items = [];

        if (column.sortable && features.sorting) {
            const [ascending, descending] = sortLabels(column);
            const active = (table.state.sort || []).find((entry) => entry.field === key);

            items.push(
                { label: ascending, icon: '↑', active: active?.direction === 'asc', onSelect: () => sortBy(key, 'asc') },
                { label: descending, icon: '↓', active: active?.direction === 'desc', onSelect: () => sortBy(key, 'desc') },
            );
        }

        if (features.grouping && ! column.computed) {
            const grouped = table.state.group === key;

            items.push({
                label: grouped ? table.t('header.ungroup') : table.t('header.group_by'),
                icon: '⊞',
                active: grouped,
                onSelect: () => {
                    table.state.group = grouped ? null : key;
                    table.refresh({ resetPage: true });
                },
            });
        }

        if (features.filters && column.filterable) {
            const filtered = table.filteredColumns().includes(key);

            // One column, one condition, right here — the whole builder is a
            // click away in the toolbar and would be a heavy answer to "show me
            // the active ones".
            items.push({
                label: table.t('header.filter_by'),
                icon: '▽',
                active: filtered,
                onSelect: async () => {
                    const filters = await table.load('filters');
                    filters?.quick?.(column.path, trigger);
                },
            });
        }

        if (items.length) items.push('-');

        if (features.column_resize) {
            items.push({ label: table.t('header.width'), icon: '↔', onSelect: () => widthDialog(column) });
        }

        if (features.column_reorder && at > -1) {
            // In RTL the visually-left neighbour is the *later* one, so the
            // wording and the movement have to be mapped, not assumed.
            const towardsStart = { label: rtl ? table.t('header.move_right') : table.t('header.move_left'), icon: rtl ? '→' : '←', delta: -1 };
            const towardsEnd = { label: rtl ? table.t('header.move_left') : table.t('header.move_right'), icon: rtl ? '←' : '→', delta: 1 };

            for (const entry of [towardsStart, towardsEnd]) {
                items.push({
                    label: entry.label,
                    icon: entry.icon,
                    disabled: entry.delta < 0 ? at === 0 : at === order.length - 1,
                    onSelect: () => move(key, entry.delta),
                });
            }
        }

        if (features.column_picker) {
            items.push({ label: table.t('header.hide'), icon: '⊘', onSelect: () => hide(key) });
        }

        if (! items.length) return;

        menu(table, trigger, items);
    }

    // Delegated, so a re-rendered header keeps working.
    table.root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dynamic-table-header-menu]');

        if (! button || ! table.root.contains(button)) return;

        event.preventDefault();
        event.stopPropagation();
        open(button.getAttribute('data-dynamic-table-header-menu'), button);
    });

    return { open };
}

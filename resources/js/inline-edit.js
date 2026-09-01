/**
 * Inline editing.
 *
 * The browser is never the source of truth: an edit is sent to Laravel, which
 * validates and saves it, and the cell is repainted from the normalised value
 * that comes back. Validation errors are shown on the cell that caused them.
 */

import { el } from './dom.js';

export default function install(table) {
    const columns = new Map(table.columns.map((column) => [column.key, column]));
    let editing = null;

    function rawValue(rowId, key) {
        const row = table.data.rows.find((candidate) => String(candidate.id) === String(rowId));

        return row?.r?.[key] ?? null;
    }

    function controlFor(column, value) {
        if (column.options?.length) {
            const node = el('select', { class: table.classes.select });

            if (column.type !== 'boolean') node.append(el('option', { value: '', text: '—' }));

            for (const option of column.options) {
                node.append(el('option', {
                    value: option.value,
                    text: option.label,
                    selected: String(option.value) === String(value ?? ''),
                }));
            }

            return node;
        }

        if (column.type === 'boolean') {
            const node = el('select', { class: table.classes.select });
            node.append(el('option', { value: '1', text: table.t('yes'), selected: value === true || value === 1 }));
            node.append(el('option', { value: '0', text: table.t('no'), selected: !(value === true || value === 1) }));

            return node;
        }

        const type = { integer: 'number', decimal: 'number', date: 'date', datetime: 'datetime-local', time: 'time', email: 'email', url: 'url' }[column.type] || 'text';
        const normalized = type === 'datetime-local' && typeof value === 'string' ? value.slice(0, 16) : value;

        return el('input', {
            type,
            step: column.type === 'decimal' ? 'any' : null,
            class: table.classes.input,
            value: normalized ?? '',
        });
    }

    function stop(commit) {
        if (!editing) return;

        const { cell, column, rowId, control, original } = editing;
        const value = control.value;

        editing = null;
        cell.classList.remove(table.classes.cellEditing, 'dynamic-table-cell-editing');

        if (!commit || String(value) === String(original ?? '')) {
            repaint(cell, column, rowId);

            return;
        }

        save(cell, column, rowId, value);
    }

    function repaint(cell, column, rowId) {
        const row = table.data.rows.find((candidate) => String(candidate.id) === String(rowId));
        table.paintCell(cell, column, row?.c?.[column.key], row);
    }

    async function save(cell, column, rowId, value) {
        // The saving and saved states are a colour change; a title says the same
        // thing to a reader who cannot see it.
        cell.classList.add('dynamic-table-cell-saving');
        cell.setAttribute('title', table.t('inline.saving'));

        try {
            const response = await table.post(table.endpoints.edit, {
                changes: [{ id: rowId, field: column.key, value }],
                state: table.serializeState(),
            });

            for (const updated of response.rows || []) {
                const index = table.data.rows.findIndex((candidate) => String(candidate.id) === String(updated.id));
                if (index > -1) table.data.rows[index] = updated;
            }

            repaint(cell, column, rowId);
            cell.classList.remove('dynamic-table-cell-invalid');
            cell.removeAttribute('title');
            flash(cell);
            table.emit('row-saved', { id: rowId, field: column.key });
        } catch (error) {
            const messages = error.payload?.errors?.[rowId]?.[column.key]
                || error.payload?.errors?.[rowId]?._
                || [error.message];

            cell.classList.add('dynamic-table-cell-invalid', table.classes.cellInvalid);
            cell.setAttribute('title', messages.join(' '));
            repaint(cell, column, rowId);
            cell.append(el('span', { class: 'dynamic-table-cell-error', text: messages[0] }));
            table.alert(messages[0], 'error');
        } finally {
            cell.classList.remove('dynamic-table-cell-saving');

            if (cell.getAttribute('title') === table.t('inline.saving')) cell.removeAttribute('title');
        }
    }

    function flash(cell) {
        cell.classList.add('dynamic-table-cell-saved');
        cell.setAttribute('title', table.t('inline.saved'));

        setTimeout(() => {
            cell.classList.remove('dynamic-table-cell-saved');
            cell.removeAttribute('title');
        }, 900);
    }

    function begin(cell) {
        if (editing) stop(true);

        const key = cell.getAttribute('data-dynamic-table-cell');
        const column = columns.get(key);
        const rowId = cell.closest('[data-dynamic-table-row]')?.getAttribute('data-dynamic-table-row');

        if (!column?.editable || !rowId) return;

        const value = rawValue(rowId, key);
        const control = controlFor(column, value);

        control.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                stop(true);
                focusNext(cell, 1);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                stop(false);
                cell.focus();
            } else if (event.key === 'Tab') {
                event.preventDefault();
                stop(true);
                focusNext(cell, event.shiftKey ? -1 : 1, true);
            }
        });

        control.addEventListener('blur', () => stop(true));

        cell.replaceChildren(control);
        cell.classList.add('dynamic-table-cell-editing', table.classes.cellEditing);
        control.focus();
        control.select?.();

        editing = { cell, column, rowId, control, original: value };
    }

    /** Move the edit cursor to the next editable cell, spreadsheet style. */
    function focusNext(cell, delta, horizontal = false) {
        const cells = [...table.root.querySelectorAll('[data-dynamic-table-editable]')];
        const index = cells.indexOf(cell);

        if (index === -1) return;

        let target;

        if (horizontal) {
            target = cells[index + delta];
        } else {
            const perRow = [...cell.closest('tr').querySelectorAll('[data-dynamic-table-editable]')].length || 1;
            target = cells[index + delta * perRow];
        }

        if (target) begin(target);
    }

    /**
     * A blank row at the top of the table.
     *
     * Creating is editing with no record yet: the same controls, the same
     * column metadata, and one POST at the end rather than one per cell —
     * a half-typed record should never reach the database.
     */
    function createRow() {
        table.root.querySelector('[data-dynamic-table-new-row]')?.remove();

        const body = table.root.querySelector('[data-dynamic-table-body]');

        if (! body) return;

        const visible = table.visibleColumns();
        const controls = new Map();
        const tr = el('tr', { class: 'dynamic-table-new-row', 'data-dynamic-table-new-row': '' });

        if (table.features.row_detail) tr.append(el('td', { class: table.classes.cell }));
        if (table.features.selection) tr.append(el('td', { class: table.classes.cell }));

        for (const column of visible) {
            const cell = el('td', { class: [table.classes.cell, `dynamic-table-align-${column.align || 'start'}`] });

            if (column.editable) {
                const control = controlFor(column, null);
                controls.set(column.key, control);
                cell.append(control);
            }

            tr.append(cell);
        }

        const save = async () => {
            const fields = {};

            for (const [key, control] of controls) {
                if (control.value !== '') fields[key] = control.value;
            }

            try {
                await table.post(table.endpoints.create, { fields, state: table.serializeState() });

                tr.remove();
                table.alert(table.t('create.saved'), 'success');
                await table.refresh();
            } catch (error) {
                const errors = error.payload?.errors || {};

                for (const [key, control] of controls) {
                    const message = errors[key]?.[0];
                    control.closest('td')?.classList.toggle('dynamic-table-cell-invalid', !! message);
                    control.title = message || '';
                }

                table.alert(Object.values(errors).flat()[0] || error.message, 'error');
            }
        };

        const buttons = el('td', { class: `${table.classes.cell} dynamic-table-row-actions-cell` }, [
            el('button', { type: 'button', class: table.classes.buttonPrimary, text: table.t('save'), onclick: save }),
            el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => tr.remove() }),
        ]);

        tr.append(buttons);
        body.prepend(tr);

        tr.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                save();
            } else if (event.key === 'Escape') {
                tr.remove();
            }
        });

        controls.values().next().value?.focus();
    }

    table.root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dynamic-table-create]');

        if (button && table.root.contains(button)) {
            event.preventDefault();
            createRow();
        }
    });

    table.root.addEventListener('dblclick', (event) => {
        const cell = event.target.closest('[data-dynamic-table-editable]');
        if (cell && table.root.contains(cell)) begin(cell);
    });

    table.root.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== 'F2') return;

        const cell = event.target.closest?.('[data-dynamic-table-editable]');
        if (cell && !editing) begin(cell);
    });

    table.on('rows-rendered', () => {
        table.root.querySelectorAll('[data-dynamic-table-editable]').forEach((cell) => {
            cell.tabIndex = 0;
            cell.setAttribute('role', 'gridcell');
        });
    });

    return { begin, stop, createRow };
}

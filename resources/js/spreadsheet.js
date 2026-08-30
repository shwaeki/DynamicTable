/**
 * Spreadsheet mode.
 *
 * Built on the same table DOM rather than a third-party grid: see
 * docs/architecture.md for the licence and feature comparison that led here.
 * The seam is deliberate — `window.DynamicTableSpreadsheetAdapter` can replace
 * this implementation with Tabulator, AG Grid or anything else without the
 * rest of the package changing.
 *
 * Provides: cell cursor, range selection, keyboard navigation, copy/paste of
 * rectangular ranges, fill-down, and a single batched save. The server remains
 * authoritative: every pasted value is validated by Laravel before it sticks.
 */

import { el } from './dom.js';

export default function install(table) {
    if (typeof window !== 'undefined' && window.DynamicTableSpreadsheetAdapter) {
        return window.DynamicTableSpreadsheetAdapter(table);
    }

    const dirty = new Map(); // `${rowId}|${columnKey}` -> value
    const history = [];
    let cursor = null; // { row, col }
    let anchor = null;
    let bar = null;

    const editableColumns = () => table.visibleColumns().filter((column) => column.editable);

    function cellAt(rowIndex, colIndex) {
        const row = table.root.querySelectorAll('[data-dt-row]')[rowIndex];
        if (!row) return null;

        const columns = editableColumns();
        const column = columns[colIndex];
        if (!column) return null;

        return row.querySelector(`[data-dt-cell="${CSS.escape(column.key)}"]`);
    }

    function coordsOf(cell) {
        const rows = [...table.root.querySelectorAll('[data-dt-row]')];
        const rowIndex = rows.indexOf(cell.closest('[data-dt-row]'));
        const colIndex = editableColumns().findIndex((column) => column.key === cell.getAttribute('data-dt-cell'));

        return rowIndex > -1 && colIndex > -1 ? { row: rowIndex, col: colIndex } : null;
    }

    function paintSelection() {
        table.root.querySelectorAll('.dt-sheet-selected, .dt-sheet-cursor')
            .forEach((node) => node.classList.remove('dt-sheet-selected', 'dt-sheet-cursor'));

        if (!cursor) return;

        const from = anchor || cursor;
        const [r1, r2] = [Math.min(from.row, cursor.row), Math.max(from.row, cursor.row)];
        const [c1, c2] = [Math.min(from.col, cursor.col), Math.max(from.col, cursor.col)];

        for (let row = r1; row <= r2; row++) {
            for (let col = c1; col <= c2; col++) {
                cellAt(row, col)?.classList.add('dt-sheet-selected');
            }
        }

        const active = cellAt(cursor.row, cursor.col);
        active?.classList.add('dt-sheet-cursor');
        active?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    function move(deltaRow, deltaCol, extend = false) {
        if (!cursor) return;

        const rows = table.root.querySelectorAll('[data-dt-row]').length;
        const cols = editableColumns().length;

        const next = {
            row: Math.max(0, Math.min(rows - 1, cursor.row + deltaRow)),
            col: Math.max(0, Math.min(cols - 1, cursor.col + deltaCol)),
        };

        cursor = next;
        if (!extend) anchor = { ...next };

        paintSelection();
    }

    function selectedCells() {
        if (!cursor) return [];

        const from = anchor || cursor;
        const [r1, r2] = [Math.min(from.row, cursor.row), Math.max(from.row, cursor.row)];
        const [c1, c2] = [Math.min(from.col, cursor.col), Math.max(from.col, cursor.col)];
        const grid = [];

        for (let row = r1; row <= r2; row++) {
            const line = [];

            for (let col = c1; col <= c2; col++) line.push({ row, col, cell: cellAt(row, col) });

            grid.push(line);
        }

        return grid;
    }

    function valueOf(rowIndex, colIndex) {
        const row = table.data.rows[rowIndex];
        const column = editableColumns()[colIndex];

        if (!row || !column) return '';

        const key = `${row.id}|${column.key}`;

        return dirty.has(key) ? dirty.get(key) : (row.r?.[column.key] ?? row.c?.[column.key] ?? '');
    }

    function setValue(rowIndex, colIndex, value, record = true) {
        const row = table.data.rows[rowIndex];
        const column = editableColumns()[colIndex];

        if (!row || !column) return;

        const key = `${row.id}|${column.key}`;

        if (record) history.push({ key, previous: dirty.has(key) ? dirty.get(key) : undefined });

        dirty.set(key, value);

        const cell = cellAt(rowIndex, colIndex);

        if (cell) {
            cell.classList.add('dt-sheet-dirty');
            cell.replaceChildren(document.createTextNode(value === null || value === '' ? '' : String(value)));
        }

        renderBar();
    }

    function undo() {
        const entry = history.pop();
        if (!entry) return;

        if (entry.previous === undefined) dirty.delete(entry.key);
        else dirty.set(entry.key, entry.previous);

        table.renderRows();
        markDirty();
        renderBar();
    }

    function markDirty() {
        for (const key of dirty.keys()) {
            const [rowId, columnKey] = key.split('|');
            const cell = table.root.querySelector(`[data-dt-row="${CSS.escape(rowId)}"] [data-dt-cell="${CSS.escape(columnKey)}"]`);

            if (cell) {
                cell.classList.add('dt-sheet-dirty');
                cell.replaceChildren(document.createTextNode(String(dirty.get(key) ?? '')));
            }
        }
    }

    function copy(event) {
        const grid = selectedCells();
        if (!grid.length) return;

        const text = grid
            .map((line) => line.map((entry) => valueOf(entry.row, entry.col)).join('\t'))
            .join('\n');

        event.clipboardData.setData('text/plain', text);
        event.preventDefault();
    }

    function paste(event) {
        if (!cursor) return;

        const text = event.clipboardData.getData('text/plain');
        if (!text) return;

        event.preventDefault();

        const lines = text.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n');
        const rows = table.data.rows.length;
        const cols = editableColumns().length;

        lines.forEach((line, rowOffset) => {
            line.split('\t').forEach((value, colOffset) => {
                const row = cursor.row + rowOffset;
                const col = cursor.col + colOffset;

                if (row < rows && col < cols) setValue(row, col, value);
            });
        });

        paintSelection();
    }

    function fillDown() {
        const grid = selectedCells();
        if (grid.length < 2) return;

        const source = grid[0];

        grid.slice(1).forEach((line) => {
            line.forEach((entry, index) => setValue(entry.row, entry.col, valueOf(source[index].row, source[index].col)));
        });
    }

    function clearSelection() {
        for (const line of selectedCells()) {
            for (const entry of line) setValue(entry.row, entry.col, '');
        }
    }

    async function save() {
        if (!dirty.size) return;

        const changes = [...dirty.entries()].map(([key, value]) => {
            const [id, field] = key.split('|');

            return { id, field, value };
        });

        try {
            const response = await table.post(table.endpoints.edit, {
                changes,
                state: table.serializeState(),
            });

            for (const updated of response.rows || []) {
                const index = table.data.rows.findIndex((row) => String(row.id) === String(updated.id));
                if (index > -1) table.data.rows[index] = updated;
            }

            dirty.clear();
            history.length = 0;
            table.renderRows();
            paintSelection();
            renderBar();
            table.alert(table.t('inline.saved'), 'success');
        } catch (error) {
            const errors = error.payload?.errors || {};

            for (const [rowId, fields] of Object.entries(errors)) {
                for (const [field, messages] of Object.entries(fields)) {
                    const cell = table.root.querySelector(`[data-dt-row="${CSS.escape(rowId)}"] [data-dt-cell="${CSS.escape(field)}"]`);

                    if (cell) {
                        cell.classList.add('dt-cell-invalid');
                        cell.setAttribute('title', messages.join(' '));
                    }
                }
            }

            table.alert(error.message || table.t('errors.generic'), 'error');
        }
    }

    function renderBar() {
        if (!bar) {
            bar = el('div', { class: 'dt-sheet-bar', role: 'status' });
            table.root.append(bar);
        }

        bar.classList.toggle('dt-hidden', dirty.size === 0);

        if (!dirty.size) return;

        bar.replaceChildren(
            el('span', { text: table.t('inline.save_all', { count: dirty.size }) }),
            el('button', {
                type: 'button',
                class: table.classes.button,
                text: table.t('inline.discard'),
                onclick: () => {
                    dirty.clear();
                    history.length = 0;
                    table.renderRows();
                    renderBar();
                },
            }),
            el('button', { type: 'button', class: table.classes.buttonPrimary, text: table.t('save'), onclick: save }),
        );
    }

    /* --------------------------------------------------------------- */

    table.root.classList.add('dt-sheet');
    table.root.tabIndex = 0;

    table.root.addEventListener('mousedown', (event) => {
        const cell = event.target.closest('[data-dt-editable]');
        if (!cell) return;

        const coords = coordsOf(cell);
        if (!coords) return;

        cursor = coords;
        if (!event.shiftKey) anchor = { ...coords };

        paintSelection();
        table.root.focus({ preventScroll: true });
    });

    table.root.addEventListener('keydown', (event) => {
        if (event.target.matches('input, select, textarea')) return;

        const step = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] }[event.key];

        if (step) {
            event.preventDefault();
            move(step[0], step[1], event.shiftKey);

            return;
        }

        if (event.key === 'Tab') {
            event.preventDefault();
            move(0, event.shiftKey ? -1 : 1);

            return;
        }

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'd') {
            event.preventDefault();
            fillDown();

            return;
        }

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            undo();

            return;
        }

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            save();

            return;
        }

        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            clearSelection();
        }
    });

    table.root.addEventListener('copy', copy);
    table.root.addEventListener('paste', paste);
    table.on('rows-rendered', () => {
        markDirty();
        paintSelection();
    });

    renderBar();

    return { save, undo, fillDown, get dirty() { return dirty; } };
}

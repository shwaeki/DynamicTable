/**
 * Row selection and bulk actions.
 *
 * "Select all matching" is stored as a mode rather than a list of ids, so the
 * browser never holds — or sends — millions of primary keys.
 */

import { el } from './dom.js';
import { dialog, menu, select } from './ui.js';

export default function install(table) {
    const summary = table.root.querySelector('[data-dynamic-table-selection-summary]');
    const actionsHost = table.root.querySelector('[data-dynamic-table-actions]');

    function reset() {
        table.selection = { mode: 'include', ids: new Set() };
        paint();
    }

    function paint() {
        const count = table.selectionCount();

        if (summary) {
            summary.replaceChildren();
            summary.classList.toggle('dynamic-table-hidden', count === 0);

            if (count) {
                summary.append(el('span', { text: table.t('selected', { count }) }));

                if (table.selection.mode === 'include' && count === table.data.rows.length && table.data.total > count) {
                    summary.append(el('button', {
                        type: 'button',
                        class: 'dynamic-table-link-button',
                        text: table.t('select_all_matching', { total: table.data.total }),
                        onclick: () => {
                            table.selection = { mode: 'exclude', ids: new Set() };
                            paint();
                            syncCheckboxes();
                        },
                    }));
                }

                summary.append(el('button', {
                    type: 'button',
                    class: 'dynamic-table-link-button',
                    text: table.t('clear_selection'),
                    onclick: () => {
                        reset();
                        syncCheckboxes();
                    },
                }));
            }
        }

        actionsHost?.classList.toggle('dynamic-table-hidden', count === 0);
        table.emit('selection-changed', count);
    }

    function syncCheckboxes() {
        table.root.querySelectorAll('[data-dynamic-table-select]').forEach((box) => {
            box.checked = table.isSelected(box.getAttribute('data-dynamic-table-select'));
        });

        const all = table.root.querySelector('[data-dynamic-table-select-all]');

        if (all) {
            const ids = table.data.rows.map((row) => String(row.id));
            const selected = ids.filter((id) => table.isSelected(id)).length;

            all.checked = ids.length > 0 && selected === ids.length;
            all.indeterminate = selected > 0 && selected < ids.length;
        }
    }

    function toggle(id, on) {
        const key = String(id);

        if (table.selection.mode === 'exclude') {
            if (on) table.selection.ids.delete(key);
            else table.selection.ids.add(key);
        } else if (on) {
            table.selection.ids.add(key);
        } else {
            table.selection.ids.delete(key);
        }

        paint();
    }

    table.root.addEventListener('change', (event) => {
        const box = event.target.closest('[data-dynamic-table-select]');

        if (box) {
            toggle(box.getAttribute('data-dynamic-table-select'), box.checked);
            syncCheckboxes();

            return;
        }

        const all = event.target.closest('[data-dynamic-table-select-all]');

        if (all) {
            for (const row of table.data.rows) toggle(row.id, all.checked);
            syncCheckboxes();
        }
    });

    // Shift-click range selection.
    let lastIndex = null;

    table.root.addEventListener('click', (event) => {
        const box = event.target.closest('[data-dynamic-table-select]');
        if (!box) return;

        const ids = table.data.rows.map((row) => String(row.id));
        const index = ids.indexOf(box.getAttribute('data-dynamic-table-select'));

        if (event.shiftKey && lastIndex !== null && index > -1) {
            const [from, to] = [Math.min(lastIndex, index), Math.max(lastIndex, index)];

            for (let cursor = from; cursor <= to; cursor++) toggle(ids[cursor], box.checked);

            syncCheckboxes();
        }

        lastIndex = index;
    });

    table.on('rows-rendered', () => {
        syncCheckboxes();
        paint();
    });

    paint();

    /**
     * One labelled input for an action field or an editable column.
     *
     * A column carries its own input type and options from the metadata engine,
     * so bulk editing an enum produces a select without anyone declaring one.
     */
    function control(config, initial, onChange) {
        if (config.options) {
            return select(table, config.options, initial, onChange);
        }

        if (config.input === 'checkbox' || config.type === 'boolean') {
            return select(table, [
                { value: 1, label: table.t('yes') },
                { value: 0, label: table.t('no') },
            ], initial, onChange);
        }

        if (config.input === 'textarea') {
            return el('textarea', {
                class: table.classes.input,
                rows: 3,
                oninput: (event) => onChange(event.target.value),
            });
        }

        return el('input', {
            type: config.input || config.type || 'text',
            class: table.classes.input,
            value: initial ?? '',
            oninput: (event) => onChange(event.target.value),
        });
    }

    /** Collect an action's declared inputs, then run it. */
    function prompt(action, execute) {
        const values = {};

        const controls = Object.entries(action.fields).map(([name, config]) => {
            values[name] = config.default ?? '';

            return el('label', { class: 'dynamic-table-field' }, [
                el('span', { class: 'dynamic-table-field-label', text: config.label || name }),
                control(config, values[name], (value) => { values[name] = value; }),
            ]);
        });

        const instance = dialog(table, {
            title: action.label,
            width: '24rem',
            body: el('div', { class: 'dynamic-table-form' }, controls),
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                el('button', {
                    type: 'button',
                    class: action.destructive || action.style === 'danger' ? table.classes.buttonDanger : table.classes.buttonPrimary,
                    text: table.t('apply'),
                    onclick: () => {
                        instance.close();
                        execute(values);
                    },
                }),
            ]),
        });
    }

    /**
     * Change the same columns on every selected record.
     *
     * Only columns the table marked editable are offered, and only the ones
     * ticked are sent — an untouched field is never written, so bulk editing
     * "status" cannot silently blank a note.
     */
    function bulkEdit() {
        const editable = (table.boot.editableColumns || [])
            .map((key) => table.columns.find((column) => column.key === key))
            .filter(Boolean);

        if (! editable.length) {
            table.alert(table.t('bulk_edit.none'), 'error');

            return;
        }

        const values = {};
        const enabled = new Set();

        const rows = editable.map((column) => {
            const toggle = el('input', {
                type: 'checkbox',
                onchange: (event) => {
                    if (event.target.checked) enabled.add(column.key);
                    else enabled.delete(column.key);
                },
            });

            // Typing into a field is itself a statement of intent, so it ticks
            // the box rather than being quietly ignored on submit.
            const input = control(column, '', (value) => {
                values[column.key] = value;
                enabled.add(column.key);
                toggle.checked = true;
            });

            return el('label', { class: 'dynamic-table-field dynamic-table-bulk-field' }, [
                el('span', { class: 'dynamic-table-field-label' }, [toggle, el('span', { text: column.label })]),
                input,
            ]);
        });

        const errors = el('div', { class: 'dynamic-table-form-errors' });

        const submit = async () => {
            const fields = {};

            for (const key of enabled) fields[key] = values[key] ?? '';

            if (! Object.keys(fields).length) {
                errors.textContent = table.t('bulk_edit.nothing');

                return;
            }

            try {
                const response = await table.post(table.endpoints.bulkEdit, {
                    fields,
                    state: table.serializeState(),
                });

                instance.close();
                table.alert(response.message, 'success');
                reset();
                await table.refresh();
            } catch (error) {
                errors.textContent = Object.values(error.payload?.errors || {}).flat().join(' ') || error.message;
            }
        };

        const instance = dialog(table, {
            title: table.t('bulk_edit.title'),
            width: '28rem',
            body: el('div', { class: 'dynamic-table-form' }, [
                el('p', { class: 'dynamic-table-hint', text: table.t('bulk_edit.hint', { count: table.selectionCount() }) }),
                ...rows,
                errors,
            ]),
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                el('button', { type: 'button', class: table.classes.buttonPrimary, text: table.t('apply'), onclick: submit }),
            ]),
        });
    }

    /**
     * Run a toolbar action.
     *
     * The same contract as a row action, minus the record: confirm, optionally
     * collect inputs, post, and let the server say whether the table needs
     * repainting afterwards.
     */
    async function runToolbar(name, button) {
        const action = (table.boot.toolbarActions || []).find((candidate) => candidate.name === name);

        if (! action || button?.disabled) return;

        const execute = async (input) => {
            if (button) button.disabled = true;

            try {
                const response = await table.post(table.endpoints.toolbarAction, {
                    action: name,
                    input,
                    state: table.serializeState(),
                });

                if (response.message) table.alert(response.message, 'success');
                if (response.refresh !== false) await table.refresh();
            } catch (error) {
                table.alert(error.message, 'error');
            } finally {
                if (button) button.disabled = false;
            }
        };

        if (action.fields) {
            prompt(action, execute);

            return;
        }

        if (action.confirm && ! window.confirm(action.confirm)) return;

        await execute({});
    }

    table.root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dynamic-table-toolbar-action]');

        if (! button || ! table.root.contains(button)) return;

        event.preventDefault();
        runToolbar(button.getAttribute('data-dynamic-table-toolbar-action'), button);
    });

    async function run(action) {
        const execute = async (input) => {
            // A bulk action runs over the whole selection, which can be every
            // row that matches the filters — long enough that a silent table
            // reads as a table that ignored the click.
            table.setLoading(true, table.t('actions.running'));

            try {
                const response = await table.post(table.endpoints.action, {
                    action: action.name,
                    input,
                    state: table.serializeState(),
                });

                table.alert(response.message, 'success');
                reset();
                await table.refresh();
            } catch (error) {
                table.alert(error.message, 'error');
            } finally {
                table.setLoading(false);
            }
        };

        if (action.fields) {
            prompt(action, execute);

            return;
        }

        if (action.confirm && !window.confirm(action.confirm)) return;

        await execute({});
    }

    /**
     * Run a single row's action.
     *
     * The button is disabled while the request is in flight, so a double click
     * cannot delete twice — and the server re-checks the action against the
     * record regardless.
     */
    async function runRow(name, rowId, button) {
        const action = (table.boot.rowActions || []).find((candidate) => candidate.name === name);

        if (! action || button.disabled) return;

        if (action.confirm && ! window.confirm(action.confirm)) return;

        button.disabled = true;

        try {
            const response = await table.post(table.endpoints.rowAction, {
                action: name,
                id: rowId,
                state: table.serializeState(),
            });

            if (response.message) table.alert(response.message, 'success');

            // A deleted row, or one that no longer matches the filters, means
            // the page is stale — reload it rather than patching around it.
            if (response.deleted || response.refresh !== false) {
                await table.refresh();

                return;
            }

            if (response.row) {
                const index = table.data.rows.findIndex((row) => String(row.id) === String(rowId));

                if (index > -1) {
                    table.data.rows[index] = response.row;
                    table.renderRows();
                }
            }
        } catch (error) {
            table.alert(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    table.root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dynamic-table-row-action]');

        if (! button || ! table.root.contains(button)) return;

        event.preventDefault();

        const rowId = button.closest('[data-dynamic-table-row]')?.getAttribute('data-dynamic-table-row');

        if (rowId) runRow(button.getAttribute('data-dynamic-table-row-action'), rowId, button);
    });

    return {
        runRow,
        runToolbar,
        bulkEdit,
        open(panel, trigger) {
            if (panel === 'bulk-edit') return bulkEdit();

            menu(table, trigger, (table.boot.actions || []).map((action) => ({
                label: action.label,
                icon: action.icon,
                danger: action.destructive,
                onSelect: () => run(action),
            })));
        },
        reset,
        paint,
    };
}

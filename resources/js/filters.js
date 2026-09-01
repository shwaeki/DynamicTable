/**
 * Advanced filter builder — the Dynamics-365-style condition tree.
 *
 * The field catalogue is fetched once, lazily, the first time the panel opens,
 * so a table that never filters never pays for relationship introspection.
 */

import { el } from './dom.js';
import { dialog, popover, select } from './ui.js';

export default function install(table) {
    let catalogue = null;

    const operatorsFor = (field) => (field?.operators || []).map((value) => ({
        value,
        label: table.t(`operators.${value}`) === `operators.${value}`
            ? value.replace(/_/g, ' ')
            : table.t(`operators.${value}`),
    }));

    const findField = (path) => {
        for (const group of catalogue?.groups || []) {
            const match = group.fields.find((candidate) => candidate.path === path);
            if (match) return match;
        }

        return null;
    };

    const arity = (operator) => {
        if (['is_empty', 'is_not_empty', 'today', 'yesterday', 'tomorrow', 'this_week', 'last_week',
            'next_week', 'this_month', 'last_month', 'next_month', 'this_year', 'last_year'].includes(operator)) return 0;
        if (['between', 'not_between'].includes(operator)) return 2;
        if (['in', 'not_in'].includes(operator)) return -1;

        return 1;
    };

    const cloneTree = (tree) => JSON.parse(JSON.stringify(tree || { logic: 'and', conditions: [] }));

    async function ensureCatalogue() {
        if (catalogue) return catalogue;

        catalogue = await table.post(table.endpoints.fields);

        return catalogue;
    }

    /**
     * Faceted counts: "Active (1,204)" beside each choice.
     *
     * Only for the columns the table opted into, and only once the dropdown
     * exists — the count is one grouped query, and asking for it on a column
     * nobody filters would be a query for nothing. The server excludes any
     * condition already set on this same column, so the other values do not
     * all read zero once one is chosen.
     */
    async function decorateWithCounts(node, field) {
        const key = String(field.path).replace(/\./g, '__');

        if (! (table.boot.filterCounts || []).includes(key)) return;

        try {
            const response = await table.post(table.endpoints.options, {
                field: field.path,
                state: table.serializeState(),
            });

            const counts = new Map(
                (response.options || [])
                    .filter((option) => option.count !== undefined)
                    .map((option) => [String(option.value), option.count]),
            );

            if (! counts.size) return;

            for (const option of node.options) {
                const count = counts.get(String(option.value));

                if (count !== undefined) {
                    option.textContent = `${option.textContent} (${new Intl.NumberFormat(table.boot.locale || undefined).format(count)})`;
                }
            }
        } catch {
            // A missing count is not worth an error: the filter still works.
        }
    }

    function valueInput(field, condition, onChange) {
        const count = arity(condition.operator);

        // The field and operator selects are labelled; without this the value
        // control is the one part of a condition a screen reader cannot name.
        const named = { 'aria-label': table.t('filter.value') };

        if (count === 0) return el('span', { class: 'dynamic-table-filter-novalue', text: '—' });

        if (field?.options?.length) {
            const node = select(
                table,
                [{ value: '', label: '—' }, ...field.options.map((option) => ({ value: option.value, label: option.label }))],
                condition.value,
                (value) => onChange(value),
                named,
            );

            decorateWithCounts(node, field);

            return node;
        }

        const inputType = { number: 'number', date: 'date', datetime: 'datetime-local', time: 'time', boolean: 'checkbox' }[field?.input] || 'text';

        if (field?.input === 'boolean') {
            return select(table, [
                { value: '1', label: table.t('yes') },
                { value: '0', label: table.t('no') },
            ], condition.value ?? '1', (value) => onChange(value), named);
        }

        if (count === 2) {
            const pair = Array.isArray(condition.value) ? condition.value : ['', ''];

            const first = el('input', {
                ...named,
                type: inputType,
                class: table.classes.input,
                value: pair[0] ?? '',
                oninput: () => onChange([first.value, second.value]),
            });

            const second = el('input', {
                ...named,
                type: inputType,
                class: table.classes.input,
                value: pair[1] ?? '',
                oninput: () => onChange([first.value, second.value]),
            });

            return el('span', { class: 'dynamic-table-filter-range' }, [first, el('span', { text: '–' }), second]);
        }

        if (count === -1) {
            return el('input', {
                ...named,
                type: 'text',
                class: table.classes.input,
                value: Array.isArray(condition.value) ? condition.value.join(', ') : (condition.value ?? ''),
                placeholder: 'a, b, c',
                oninput: (event) => onChange(event.target.value.split(',').map((part) => part.trim()).filter(Boolean)),
            });
        }

        return el('input', {
            ...named,
            type: inputType,
            class: table.classes.input,
            value: condition.value ?? '',
            oninput: (event) => onChange(event.target.value),
        });
    }

    function renderCondition(condition, parent, index, repaint) {
        const field = findField(condition.field);

        const fieldOptions = [];

        for (const group of catalogue.groups) {
            fieldOptions.push({ value: '', label: `── ${group.label} ──`, disabled: true });

            for (const candidate of group.fields) {
                if (!candidate.filterable) continue;
                fieldOptions.push({ value: candidate.path, label: candidate.label });
            }
        }

        return el('div', { class: 'dynamic-table-condition' }, [
            select(table, fieldOptions, condition.field, (value) => {
                condition.field = value;
                condition.operator = findField(value)?.operators?.[0] || 'equals';
                condition.value = null;
                repaint();
            }, { 'aria-label': table.t('filter.field') }),

            select(table, operatorsFor(field), condition.operator, (value) => {
                condition.operator = value;
                condition.value = null;
                repaint();
            }, { 'aria-label': table.t('filter.operator') }),

            valueInput(field, condition, (value) => {
                condition.value = value;
            }),

            el('button', {
                type: 'button',
                class: 'dynamic-table-condition-remove',
                'aria-label': table.t('filter.remove'),
                text: '×',
                onclick: () => {
                    parent.conditions.splice(index, 1);
                    repaint();
                },
            }),
        ]);
    }

    function renderGroup(group, repaint, depth = 0) {
        const node = el('div', { class: `dynamic-table-filter-group dynamic-table-filter-depth-${depth}` });

        /*
         * The group's header: how its rows are joined, and — for a nested
         * group — the control that removes the whole branch.
         *
         * That control used to trail the Add buttons at the foot of the group,
         * where it read as "undo the last thing I added" rather than "remove
         * this group". At the head, beside the And/Or it belongs to, it says
         * what it does.
         */
        const logic = el('div', { class: 'dynamic-table-filter-logic' }, [
            select(table, [
                { value: 'and', label: table.t('filter.and') },
                { value: 'or', label: table.t('filter.or') },
            ], group.logic || 'and', (value) => {
                group.logic = value;
            }, { 'aria-label': table.t('filter.operator') }),

            depth > 0
                ? el('button', {
                    type: 'button',
                    class: 'dynamic-table-condition-remove dynamic-table-filter-group-remove',
                    'aria-label': table.t('filter.remove_group'),
                    title: table.t('filter.remove_group'),
                    text: '×',
                    onclick: () => {
                        group.conditions = [];
                        group._removed = true;
                        repaint();
                    },
                })
                : null,
        ]);

        node.append(logic);

        // Any empty group is otherwise two buttons and nothing else, which
        // reads as a broken panel rather than as "there is nothing here yet".
        if (! (group.conditions || []).length) {
            node.append(el('p', { class: 'dynamic-table-hint', text: table.t('filter.empty') }));
        }

        (group.conditions || []).forEach((child, index) => {
            node.append(child.conditions
                ? renderGroup(child, repaint, depth + 1)
                : renderCondition(child, group, index, repaint));
        });

        node.append(el('div', { class: 'dynamic-table-filter-group-actions' }, [
            el('button', {
                type: 'button',
                class: table.classes.button,
                text: table.t('filter.add'),
                onclick: () => {
                    const first = catalogue.groups[0]?.fields?.find((field) => field.filterable);
                    group.conditions = group.conditions || [];
                    group.conditions.push({
                        field: first?.path || '',
                        operator: first?.operators?.[0] || 'equals',
                        value: null,
                    });
                    repaint();
                },
            }),
            depth < 3 ? el('button', {
                type: 'button',
                class: table.classes.button,
                text: table.t('filter.add_group'),
                onclick: () => {
                    group.conditions = group.conditions || [];
                    group.conditions.push({ logic: 'or', conditions: [] });
                    repaint();
                },
            }) : null,
        ]));

        return node;
    }

    function countConditions(group) {
        return (group?.conditions || []).reduce(
            (total, child) => total + (child.conditions ? countConditions(child) : 1),
            0,
        );
    }

    function prune(group) {
        if (!group.conditions) return group;

        group.conditions = group.conditions
            .filter((child) => !(child.conditions && (child._removed || !child.conditions.length)))
            .map((child) => (child.conditions ? prune(child) : child))
            .filter((child) => child.conditions || (child.field && child.operator));

        return group;
    }

    /* ------------------------------------------------------------------ */
    /* Quick filter, from the column header menu                           */
    /* ------------------------------------------------------------------ */

    /** Every condition anywhere in the tree that is about this field. */
    function conditionsOn(node, path, found = []) {
        for (const child of node?.conditions || []) {
            if (child.conditions) conditionsOn(child, path, found);
            else if (child.field === path) found.push(child);
        }

        return found;
    }

    /** The same tree with this field's conditions taken out, at any depth. */
    function without(node, path) {
        const conditions = [];

        for (const child of node?.conditions || []) {
            if (child.conditions) {
                const inner = without(child, path);

                if (inner.conditions.length) conditions.push(inner);
            } else if (child.field !== path) {
                conditions.push(child);
            }
        }

        return { logic: node?.logic || 'and', conditions };
    }

    /**
     * One column, one condition, in a small panel — the Dynamics "Filter by".
     *
     * It writes into the same filter tree the builder edits, so a filter set
     * here appears in the filter panel, counts towards the badge, is saved with
     * a view and is applied by exactly the same code on the server. This is a
     * shortcut to the tree, never a second kind of filter.
     */
    async function quick(path, trigger) {
        await ensureCatalogue();

        const field = findField(path);

        if (! field) return;

        const existing = conditionsOn(table.state.filters, path)[0];
        const condition = {
            field: path,
            operator: existing?.operator || field.operators?.[0] || 'equals',
            value: existing ? existing.value : '',
        };

        const control = el('div', { class: 'dynamic-table-quick-value' });

        const paintValue = () => {
            control.replaceChildren(valueInput(field, condition, (value) => { condition.value = value; }));
        };

        paintValue();

        const apply = () => {
            const tree = without(table.state.filters, path);

            if (arity(condition.operator) === 0 || `${condition.value}` !== '') {
                tree.conditions.push({ ...condition });
            }

            table.state.filters = tree.conditions.length ? tree : null;
            instance.close();
            updateBadge();
            table.refresh({ resetPage: true });
        };

        const instance = popover(table, trigger, {
            title: table.t('header.filter_by'),
            body: el('div', { class: 'dynamic-table-form dynamic-table-quick-filter' }, [
                select(table, operatorsFor(field), condition.operator, (value) => {
                    condition.operator = value;
                    condition.value = '';
                    paintValue();
                }, { 'aria-label': table.t('filter.operator') }),
                control,
            ]),
            footer: el('div', { class: 'dynamic-table-popover-actions' }, [
                el('button', {
                    type: 'button',
                    class: table.classes.button,
                    text: table.t('filters_panel.clear'),
                    disabled: ! existing,
                    onclick: () => {
                        const tree = without(table.state.filters, path);

                        table.state.filters = tree.conditions.length ? tree : null;
                        instance.close();
                        updateBadge();
                        table.refresh({ resetPage: true });
                    },
                }),
                el('button', { type: 'button', class: table.classes.buttonPrimary, text: table.t('apply'), onclick: apply }),
            ]),
        });
    }

    function updateBadge() {
        const badge = table.root.querySelector('[data-dynamic-table-filter-count]');
        if (!badge) return;

        const count = countConditions(table.state.filters);

        badge.textContent = String(count);
        // A bare number beside "Filters" is unreadable to a screen reader.
        badge.title = table.t('filter.active', { count });
        badge.setAttribute('aria-label', badge.title);
        badge.classList.toggle('dynamic-table-hidden', count === 0);
    }

    table.on('updated', updateBadge);
    updateBadge();

    return {
        quick,
        /**
         * @param {string|null} seedPath opened from a column header menu, so
         *        the builder starts with a condition on that column rather
         *        than an empty group the user has to fill in twice.
         */
        async open(panel, trigger, seedPath = null) {
            await ensureCatalogue();

            let working = cloneTree(table.state.filters);

            if (seedPath) {
                const field = findField(seedPath);

                working.conditions = working.conditions || [];

                if (field && ! working.conditions.some((child) => child.field === seedPath)) {
                    working.conditions.push({
                        field: seedPath,
                        operator: field.operators?.[0] || 'equals',
                        value: null,
                    });
                }
            }
            const container = el('div', { class: 'dynamic-table-filter-builder' });

            const repaint = () => {
                container.replaceChildren(renderGroup(working, repaint));
            };

            repaint();

            const instance = dialog(table, {
                title: table.t('filters'),
                width: '46rem',
                body: container,
                footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                    el('button', {
                        type: 'button',
                        class: table.classes.button,
                        text: table.t('reset'),
                        onclick: () => {
                            working = { logic: 'and', conditions: [] };
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
                            const pruned = prune(working);
                            table.state.filters = pruned.conditions.length ? pruned : null;
                            instance.close();
                            updateBadge();
                            table.refresh({ resetPage: true });
                        },
                    }),
                ]),
            });
        },
        updateBadge,
    };
}

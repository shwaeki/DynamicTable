/**
 * Advanced filter builder — the Dynamics-365-style condition tree.
 *
 * The field catalogue is fetched once, lazily, the first time the panel opens,
 * so a table that never filters never pays for relationship introspection.
 */

import { el } from './dom.js';
import { dialog, select } from './ui.js';

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

        if (! (table.boot.facets || []).includes(key)) return;

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

        if (count === 0) return el('span', { class: 'dt-filter-novalue', text: '—' });

        if (field?.options?.length) {
            const node = select(
                table,
                [{ value: '', label: '—' }, ...field.options.map((option) => ({ value: option.value, label: option.label }))],
                condition.value,
                (value) => onChange(value),
            );

            decorateWithCounts(node, field);

            return node;
        }

        const inputType = { number: 'number', date: 'date', datetime: 'datetime-local', time: 'time', boolean: 'checkbox' }[field?.input] || 'text';

        if (field?.input === 'boolean') {
            return select(table, [
                { value: '1', label: table.t('yes') },
                { value: '0', label: table.t('no') },
            ], condition.value ?? '1', (value) => onChange(value));
        }

        if (count === 2) {
            const pair = Array.isArray(condition.value) ? condition.value : ['', ''];

            const first = el('input', {
                type: inputType,
                class: table.classes.input,
                value: pair[0] ?? '',
                oninput: () => onChange([first.value, second.value]),
            });

            const second = el('input', {
                type: inputType,
                class: table.classes.input,
                value: pair[1] ?? '',
                oninput: () => onChange([first.value, second.value]),
            });

            return el('span', { class: 'dt-filter-range' }, [first, el('span', { text: '–' }), second]);
        }

        if (count === -1) {
            return el('input', {
                type: 'text',
                class: table.classes.input,
                value: Array.isArray(condition.value) ? condition.value.join(', ') : (condition.value ?? ''),
                placeholder: 'a, b, c',
                oninput: (event) => onChange(event.target.value.split(',').map((part) => part.trim()).filter(Boolean)),
            });
        }

        return el('input', {
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

        return el('div', { class: 'dt-condition' }, [
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
                class: 'dt-condition-remove',
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
        const node = el('div', { class: `dt-filter-group dt-filter-depth-${depth}` });

        const logic = el('div', { class: 'dt-filter-logic' }, [
            select(table, [
                { value: 'and', label: table.t('filter.and') },
                { value: 'or', label: table.t('filter.or') },
            ], group.logic || 'and', (value) => {
                group.logic = value;
            }, { 'aria-label': table.t('filter.operator') }),
        ]);

        node.append(logic);

        (group.conditions || []).forEach((child, index) => {
            node.append(child.conditions
                ? renderGroup(child, repaint, depth + 1)
                : renderCondition(child, group, index, repaint));
        });

        node.append(el('div', { class: 'dt-filter-group-actions' }, [
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
            depth > 0 ? el('button', {
                type: 'button',
                class: 'dt-condition-remove',
                'aria-label': table.t('filter.remove'),
                text: '×',
                onclick: () => {
                    group.conditions = [];
                    group._removed = true;
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

    function updateBadge() {
        const badge = table.root.querySelector('[data-dt-filter-count]');
        if (!badge) return;

        const count = countConditions(table.state.filters);

        badge.textContent = String(count);
        badge.classList.toggle('dt-hidden', count === 0);
    }

    table.on('updated', updateBadge);
    updateBadge();

    return {
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
            const container = el('div', { class: 'dt-filter-builder' });

            const repaint = () => {
                container.replaceChildren(renderGroup(working, repaint));
            };

            repaint();

            const instance = dialog(table, {
                title: table.t('filters'),
                width: '46rem',
                body: container,
                footer: el('div', { class: 'dt-modal-actions' }, [
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

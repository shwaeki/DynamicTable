/**
 * Saved views.
 *
 * A menu to switch views quickly, and a manager dialog where the user can set
 * or clear their default view, rename, share and delete — the Dynamics 365
 * model, where "which view opens by default" is the user's choice rather than
 * something only a developer can decide.
 */

import { el } from './dom.js';
import { dialog, menu } from './ui.js';

export default function install(table) {
    let views = null;
    let canManageSystem = false;
    let sharingEnabled = false;

    const base = table.endpoints.views.replace(/\/?$/, '');

    async function load(force = false) {
        if (views && ! force) return views;

        const response = await table.post(table.endpoints.views);
        views = response.views || [];
        canManageSystem = !! response.canManageSystem;
        sharingEnabled = response.sharing !== false;

        return views;
    }

    function label(text) {
        const node = table.root.querySelector('[data-dynamic-table-view-label]');

        if (node) node.textContent = text;
    }

    function apply(view) {
        table.applyView(view.configuration || {}, view?.id ?? null);
        label(view?.name ?? table.boot.title);
        table.emit('view-applied', view);
    }

    /** Back to the table's own defaults, discarding the applied view. */
    function reset() {
        apply(null);
    }

    function current() {
        return (views || []).find((view) => view.id === table.state.view) || null;
    }

    /**
     * A preset lives in code, so it can be applied but never edited. A view
     * shared with me is read-only too: the server says so, and the UI agrees.
     */
    const editable = (view) => view.canEdit ?? (! view.preset && (! view.system || canManageSystem));

    /** Where a view came from, at a glance. */
    function badgeFor(view) {
        if (view.preset) return { icon: '⚙', label: table.t('views.built_in') };
        if (view.system) return { icon: '🌐', label: table.t('views.shared') };
        if (view.sharedWithMe) return { icon: '👥', label: table.t('views.shared_with_me') };
        if (view.shareCount > 0) return { icon: '🔗', label: table.t('views.shared_count', { count: view.shareCount }) };

        return { icon: '👤', label: table.t('views.mine') };
    }

    async function act(view, action, body = {}) {
        const response = await table.post(`${base}/${encodeURIComponent(view.id)}/${action}`, body);

        if (response?.views) views = response.views;
        else await load(true);

        return response;
    }

    /* ---------------------------------------------------------------- */

    async function saveAs() {
        const name = el('input', { type: 'text', class: table.classes.input, maxlength: 150, placeholder: table.t('views.name') });
        const shared = el('input', { type: 'checkbox' });
        const makeDefault = el('input', { type: 'checkbox' });
        const status = el('p', { class: 'dynamic-table-error' });

        const instance = dialog(table, {
            title: table.t('views.save_as'),
            width: '26rem',
            body: el('div', { class: 'dynamic-table-form' }, [
                el('label', { class: 'dynamic-table-field' }, [
                    el('span', { class: 'dynamic-table-field-label', text: table.t('views.name') }),
                    name,
                ]),
                el('label', { class: 'dynamic-table-field dynamic-table-field-inline' }, [
                    makeDefault, el('span', { text: table.t('views.make_default') }),
                ]),
                canManageSystem
                    ? el('label', { class: 'dynamic-table-field dynamic-table-field-inline' }, [shared, el('span', { text: table.t('views.share') })])
                    : null,
                status,
            ]),
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                el('button', {
                    type: 'button',
                    class: table.classes.buttonPrimary,
                    text: table.t('save'),
                    onclick: async () => {
                        if (! name.value.trim()) {
                            name.focus();

                            return;
                        }

                        try {
                            const response = await table.post(`${base}/create`, {
                                name: name.value.trim(),
                                system: shared.checked,
                                state: table.serializeState(),
                            });

                            await load(true);
                            table.state.view = response.view.id;
                            label(response.view.name);

                            if (makeDefault.checked) {
                                await act(response.view, 'default', { default: true });
                            }

                            instance.close();
                            table.alert(table.t('views.created'), 'success');
                        } catch (error) {
                            status.textContent = error.message;
                        }
                    },
                }),
            ]),
        });
    }

    async function rename(view) {
        const name = el('input', { type: 'text', class: table.classes.input, maxlength: 150, value: view.name });

        const instance = dialog(table, {
            title: table.t('views.rename'),
            width: '24rem',
            body: el('div', { class: 'dynamic-table-form' }, [
                el('label', { class: 'dynamic-table-field' }, [
                    el('span', { class: 'dynamic-table-field-label', text: table.t('views.name') }),
                    name,
                ]),
            ]),
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                el('button', {
                    type: 'button',
                    class: table.classes.buttonPrimary,
                    text: table.t('save'),
                    onclick: async () => {
                        await act(view, 'update', { name: name.value.trim() });
                        instance.close();
                        table.alert(table.t('views.updated'), 'success');
                        manage();
                    },
                }),
            ]),
        });
    }

    /**
     * Share a view with named people.
     *
     * Read access only — the owner keeps rename, edit and delete. Recipients who
     * want their own version save a copy, which is a far simpler model to reason
     * about than per-person permissions.
     */
    async function share(view) {
        const url = `${base}/${encodeURIComponent(view.id)}`;

        let selected = [];
        const chosen = el('div', { class: 'dynamic-table-share-chosen' });
        const results = el('div', { class: 'dynamic-table-share-results' });
        const status = el('p', { class: 'dynamic-table-hint' });

        const paintChosen = () => {
            chosen.replaceChildren(
                el('p', { class: 'dynamic-table-field-label', text: table.t('views.shared_with') }),
                selected.length
                    ? el('div', { class: 'dynamic-table-share-list' }, selected.map((person) => el('span', { class: 'dynamic-table-share-pill' }, [
                        el('span', { text: person.name }),
                        el('button', {
                            type: 'button',
                            'aria-label': table.t('views.unshare', { name: person.name }),
                            text: '×',
                            onclick: () => {
                                selected = selected.filter((candidate) => candidate.id !== person.id);
                                paintChosen();
                            },
                        }),
                    ])))
                    : el('p', { class: 'dynamic-table-hint', text: table.t('views.not_shared') }),
            );
        };

        const load = async (term = '') => {
            const response = await table.post(`${url}/shares`, { search: term });

            if (term === '') selected = response.sharedWith || [];

            const ids = new Set(selected.map((person) => person.id));

            results.replaceChildren(...(response.candidates || [])
                .filter((person) => ! ids.has(person.id))
                .map((person) => el('button', {
                    type: 'button',
                    class: 'dynamic-table-share-candidate',
                    text: person.name,
                    onclick: () => {
                        selected = [...selected, person];
                        paintChosen();
                        person.hidden = true;
                        results.querySelectorAll('.dynamic-table-share-candidate').forEach((node) => {
                            if (node.textContent === person.name) node.remove();
                        });
                    },
                })));

            if (! results.childElementCount) {
                results.append(el('p', { class: 'dynamic-table-hint', text: table.t('views.no_people') }));
            }

            paintChosen();
        };

        const search = el('input', {
            type: 'search',
            class: table.classes.input,
            placeholder: table.t('views.search_people'),
            oninput: (event) => {
                clearTimeout(search._timer);
                search._timer = setTimeout(() => load(event.target.value.trim()), 300);
            },
        });

        const instance = dialog(table, {
            title: table.t('views.share_title', { name: view.name }),
            width: '30rem',
            body: el('div', { class: 'dynamic-table-form' }, [
                chosen,
                el('label', { class: 'dynamic-table-field' }, [
                    el('span', { class: 'dynamic-table-field-label', text: table.t('views.add_people') }),
                    search,
                ]),
                results,
                el('p', { class: 'dynamic-table-hint', text: table.t('views.share_hint') }),
                status,
            ]),
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                el('button', {
                    type: 'button',
                    class: table.classes.buttonPrimary,
                    text: table.t('save'),
                    onclick: async () => {
                        try {
                            const response = await table.post(`${url}/share`, {
                                users: selected.map((person) => person.id),
                            });

                            if (response?.views) views = response.views;

                            instance.close();
                            table.alert(table.t('views.share_saved'), 'success');
                            manage();
                        } catch (error) {
                            status.textContent = error.message;
                            status.className = 'dynamic-table-error';
                        }
                    },
                }),
            ]),
        });

        await load();
    }

    /** The manager: this is where a user picks which view opens by default. */
    async function manage() {
        await load(true);

        const list = el('div', { class: 'dynamic-table-views' });

        const repaint = () => {
            const sections = [
                ['views.my_views', (view) => ! view.system],
                ['views.system_views', (view) => view.system && ! view.preset],
                ['views.presets', (view) => !! view.preset],
            ];

            const nodes = [];

            for (const [labelKey, filter] of sections) {
                const matching = views.filter(filter);

                if (! matching.length) continue;

                nodes.push(el('h3', { class: 'dynamic-table-subtitle', text: table.t(labelKey) }));

                for (const view of matching) {
                    nodes.push(renderRow(view));
                }
            }

            if (! nodes.length) nodes.push(el('p', { class: 'dynamic-table-hint', text: table.t('views.empty') }));

            list.replaceChildren(...nodes);
        };

        const renderRow = (view) => {
            const isDefault = !! view.default;
            const canEdit = editable(view);

            const star = el('button', {
                type: 'button',
                class: ['dynamic-table-view-star', isDefault ? 'is-default' : null],
                text: isDefault ? '★' : '☆',
                title: isDefault ? table.t('views.clear_default') : table.t('views.set_default'),
                'aria-pressed': String(isDefault),
                // A preset's default lives in code, so its star is read-only.
                disabled: view.preset,
                onclick: async () => {
                    await act(view, 'default', { default: ! isDefault });
                    repaint();
                    table.alert(isDefault ? table.t('views.default_cleared') : table.t('views.default_set'), 'success');
                },
            });

            const badge = badgeFor(view);

            return el('div', { class: ['dynamic-table-view-row', table.state.view === view.id ? 'is-active' : null] }, [
                star,
                el('span', { class: 'dynamic-table-view-icon', title: badge.label, 'aria-label': badge.label, text: badge.icon }),
                el('button', {
                    type: 'button',
                    class: 'dynamic-table-view-name',
                    onclick: () => {
                        apply(view);
                        instance.close();
                    },
                }, [
                    el('span', { text: view.name }),
                    // Whose view this is, when it is not mine.
                    view.owner && ! view.mine
                        ? el('small', { class: 'dynamic-table-view-owner', text: table.t('views.owned_by', { name: view.owner }) })
                        : null,
                ]),
                view.shareCount > 0
                    ? el('span', { class: table.classes.badge, text: table.t('views.shared_count', { count: view.shareCount }) })
                    : null,
                // Only a private view I own can be shared with named people; a
                // system view is already visible to everyone.
                view.mine && ! view.system && sharingEnabled
                    ? el('button', {
                        type: 'button',
                        class: 'dynamic-table-view-action',
                        text: table.t('views.share_action'),
                        onclick: () => share(view),
                    })
                    : null,
                canEdit ? el('button', {
                    type: 'button',
                    class: 'dynamic-table-view-action',
                    text: table.t('edit'),
                    onclick: () => rename(view),
                }) : null,
                canEdit ? el('button', {
                    type: 'button',
                    class: 'dynamic-table-view-action dynamic-table-view-action-danger',
                    text: table.t('delete'),
                    onclick: async () => {
                        if (! window.confirm(table.t('views.delete_confirm'))) return;

                        await act(view, 'delete');

                        if (table.state.view === view.id) table.state.view = null;

                        repaint();
                        table.alert(table.t('views.deleted'), 'success');
                    },
                }) : null,
            ]);
        };

        repaint();

        const instance = dialog(table, {
            title: table.t('views.manage'),
            width: '34rem',
            body: list,
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('close'), onclick: () => instance.close() }),
                el('button', {
                    type: 'button',
                    class: table.classes.buttonPrimary,
                    text: table.t('views.save_as'),
                    onclick: () => saveAs(),
                }),
            ]),
        });
    }

    /* ---------------------------------------------------------------- */

    return {
        /**
         * The view picker, modelled on Dynamics 365: a searchable list with a
         * tick on the current view and a marker on the default one, then the
         * reset and manage actions.
         */
        async open(panel, trigger) {
            await load(true);

            const items = [];
            const active = current();

            // Default first, then the rest — the order people scan in.
            const ordered = [...views].sort((a, b) => Number(!! b.default) - Number(!! a.default));

            for (const view of ordered) {
                items.push({
                    label: view.name,
                    icon: view.default ? '👤' : null,
                    badge: view.default ? table.t('views.is_default') : null,
                    active: table.state.view === view.id,
                    onSelect: () => apply(view),
                });
            }

            if (items.length) items.push('-');

            if (active) {
                items.push({
                    label: table.t('views.reset_default'),
                    icon: '↺',
                    onSelect: () => reset(),
                });
            }

            if (active && ! active.preset && editable(active)) {
                items.push({
                    label: table.t('views.save_current'),
                    onSelect: async () => {
                        await act(active, 'update', { saveState: true, state: table.serializeState() });
                        table.alert(table.t('views.updated'), 'success');
                    },
                });
            }

            items.push({ label: table.t('views.save_as'), onSelect: () => saveAs() });
            items.push({ label: table.t('views.manage'), icon: '⚙', onSelect: () => manage() });

            menu(table, trigger, items, { search: table.t('views.search') });
        },
        manage,
        reset,
        load,
    };
}

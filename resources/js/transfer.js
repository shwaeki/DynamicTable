/**
 * Export and import dialogs, including the mapping step and poll-based
 * progress for queued jobs.
 */

import { el } from './dom.js';
import { dialog, select } from './ui.js';

export default function install(table) {
    function download(url, body) {
        // A form post keeps the browser's own download UI and works with CSRF.
        const form = el('form', { method: 'POST', action: url, target: '_blank', class: 'dt-hidden' });
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const add = (name, value) => form.append(el('input', { type: 'hidden', name, value }));

        add('_token', token);
        add('table', table.key);

        for (const [name, value] of Object.entries(body)) {
            add(name, typeof value === 'object' ? JSON.stringify(value) : String(value));
        }

        document.body.append(form);
        form.submit();
        form.remove();
    }

    async function poll(progressId, onUpdate) {
        for (;;) {
            const progress = await table.post(table.endpoints.progress, { id: progressId });

            onUpdate(progress);

            if (progress.status === 'completed' || progress.status === 'failed') return progress;

            await new Promise((resolve) => setTimeout(resolve, 1500));
        }
    }

    function progressBar() {
        const bar = el('div', { class: 'dt-progress-bar' });
        const label = el('div', { class: 'dt-progress-label' });

        return {
            node: el('div', { class: 'dt-progress' }, [el('div', { class: 'dt-progress-track' }, [bar]), label]),
            update(progress) {
                bar.style.width = `${progress.percent || 0}%`;
                label.textContent = `${progress.percent || 0}% — ${progress.processed || 0} / ${progress.total || 0}`;
            },
        };
    }

    function openExport() {
        let scope = 'view';
        let format = 'csv';

        const scopes = [
            { value: 'view', label: table.t('export.current_view') },
            { value: 'page', label: table.t('export.current_page') },
            { value: 'all', label: table.t('export.all') },
        ];

        if (table.selectionCount() > 0) {
            scopes.unshift({ value: 'selected', label: table.t('export.selected') });
            scope = 'selected';
        }

        const status = el('div', { class: 'dt-status' });

        const instance = dialog(table, {
            title: table.t('export.title'),
            width: '24rem',
            body: el('div', { class: 'dt-form' }, [
                el('label', { class: 'dt-field' }, [
                    el('span', { class: 'dt-field-label', text: table.t('export.title') }),
                    select(table, scopes, scope, (value) => { scope = value; }),
                ]),
                el('label', { class: 'dt-field' }, [
                    el('span', { class: 'dt-field-label', text: table.t('export.format') }),
                    select(table, (table.boot.exportFormats || ['csv', 'xlsx']).map((value) => ({ value, label: value.toUpperCase() })), format, (value) => { format = value; }),
                ]),
                status,
            ]),
            footer: el('div', { class: 'dt-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                el('button', {
                    type: 'button',
                    class: table.classes.buttonPrimary,
                    text: table.t('export.title'),
                    onclick: async () => {
                        // Ask the server first: it decides whether this is small
                        // enough to stream or big enough to queue.
                        try {
                            const response = await table.post(table.endpoints.export, {
                                scope,
                                format,
                                state: table.serializeState(),
                                probe: true,
                            });

                            if (response?.queued) {
                                const bar = progressBar();
                                status.replaceChildren(el('p', { text: table.t('export.queued') }), bar.node);

                                const done = await poll(response.progress, bar.update);

                                if (done.status === 'completed' && done.url) {
                                    status.replaceChildren(el('p', { text: table.t('export.ready') }));
                                    download(done.url, { id: response.progress });
                                    instance.close();
                                } else {
                                    status.replaceChildren(el('p', { class: 'dt-error', text: done.message || table.t('errors.generic') }));
                                }

                                return;
                            }

                            // Small enough to stream: let the browser download it.
                            download(table.endpoints.export, { scope, format, state: table.serializeState() });
                            instance.close();
                        } catch (error) {
                            status.replaceChildren(el('p', { class: 'dt-error', text: error.message || table.t('errors.generic') }));
                        }
                    },
                }),
            ]),
        });
    }

    function openImport() {
        let analysis = null;
        let mapping = {};
        let mode = 'create';
        let matchBy = '';

        const body = el('div', { class: 'dt-form' });
        const status = el('div', { class: 'dt-status' });

        const fileInput = el('input', {
            type: 'file',
            accept: '.csv,.txt,.xlsx,.xls',
            class: table.classes.input,
            onchange: async () => {
                if (!fileInput.files?.length) return;

                const data = new FormData();
                data.append('file', fileInput.files[0]);
                data.append('table', table.key);

                status.textContent = table.t('loading');

                try {
                    const response = await fetch(table.endpoints.import.replace(/\/?$/, '/analyze'), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        },
                        body: data,
                    });

                    if (!response.ok) throw new Error(table.t('errors.generic'));

                    analysis = await response.json();
                    mapping = { ...analysis.mapping };
                    status.textContent = '';
                    paint();
                } catch (error) {
                    status.textContent = error.message;
                }
            },
        });

        function paint() {
            const nodes = [
                el('label', { class: 'dt-field' }, [
                    el('span', { class: 'dt-field-label', text: table.t('import.file') }),
                    fileInput,
                ]),
                el('button', {
                    type: 'button',
                    class: table.classes.button,
                    text: table.t('import.template'),
                    onclick: () => download(table.endpoints.import.replace(/import$/, 'template'), { format: 'csv' }),
                }),
            ];

            if (analysis) {
                const fieldOptions = [
                    { value: '', label: table.t('import.ignore') },
                    ...Object.values(analysis.fields).map((field) => ({ value: field.key, label: field.label })),
                ];

                nodes.push(el('label', { class: 'dt-field' }, [
                    el('span', { class: 'dt-field-label', text: table.t('import.mode') }),
                    select(table, [
                        { value: 'create', label: table.t('import.mode_create') },
                        { value: 'update', label: table.t('import.mode_update') },
                        { value: 'upsert', label: table.t('import.mode_upsert') },
                    ], mode, (value) => { mode = value; paint(); }),
                ]));

                if (mode !== 'create') {
                    nodes.push(el('label', { class: 'dt-field' }, [
                        el('span', { class: 'dt-field-label', text: table.t('import.match_by') }),
                        select(table, Object.values(analysis.fields).map((field) => ({ value: field.path, label: field.label })), matchBy, (value) => { matchBy = value; }),
                    ]));
                }

                nodes.push(el('h3', { class: 'dt-subtitle', text: table.t('import.mapping') }));

                const list = el('div', { class: 'dt-mapping' });

                analysis.headings.forEach((heading, index) => {
                    list.append(el('div', { class: 'dt-mapping-row' }, [
                        el('span', { class: 'dt-mapping-source', text: heading || `#${index + 1}` }),
                        el('span', { text: '→' }),
                        select(table, fieldOptions, mapping[index] || '', (value) => { mapping[index] = value || null; }),
                    ]));
                });

                nodes.push(list);

                if (analysis.preview?.length) {
                    nodes.push(el('h3', { class: 'dt-subtitle', text: table.t('import.preview') }));
                    nodes.push(el('div', { class: 'dt-preview' }, [
                        el('table', { class: table.classes.table }, [
                            el('thead', {}, [el('tr', {}, analysis.headings.map((heading) => el('th', { text: heading })))]),
                            el('tbody', {}, analysis.preview.map((row) => el('tr', {}, row.map((cell) => el('td', { text: cell ?? '' }))))),
                        ]),
                    ]));
                }
            }

            nodes.push(status);
            body.replaceChildren(...nodes);
        }

        paint();

        const instance = dialog(table, {
            title: table.t('import.title'),
            width: '48rem',
            body,
            footer: el('div', { class: 'dt-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                el('button', {
                    type: 'button',
                    class: table.classes.buttonPrimary,
                    text: table.t('import.run'),
                    onclick: async () => {
                        if (!analysis) return;

                        try {
                            const response = await table.post(table.endpoints.import, {
                                file: analysis.file,
                                token: analysis.token,
                                mapping,
                                mode,
                                matchBy: matchBy || null,
                            });

                            if (response.queued) {
                                const bar = progressBar();
                                status.replaceChildren(el('p', { text: table.t('import.queued') }), bar.node);
                                const done = await poll(response.progress, bar.update);
                                status.replaceChildren(el('p', { text: summaryText(done) }));
                            } else {
                                status.replaceChildren(el('p', { text: summaryText(response) }));
                            }

                            await table.refresh();
                        } catch (error) {
                            status.replaceChildren(el('p', { class: 'dt-error', text: error.message }));
                        }
                    },
                }),
            ]),
        });

        function summaryText(result) {
            return table.t('import.summary', {
                created: result.created || 0,
                updated: result.updated || 0,
                failed: result.failed || 0,
            });
        }
    }

    return {
        open(panel) {
            if (panel === 'export') openExport();
            else openImport();
        },
    };
}

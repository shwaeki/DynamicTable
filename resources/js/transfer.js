/**
 * Export and import dialogs, including the mapping step and poll-based
 * progress for queued jobs.
 */

import { el, mount } from './dom.js';
import { dialog, select } from './ui.js';

export default function install(table) {
    function download(url, body) {
        // A form post keeps the browser's own download UI and works with CSRF.
        const form = el('form', { method: 'POST', action: url, target: '_blank', class: 'dynamic-table-hidden' });
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

    /** The formats this application can write, preferred first. */
    const formats = () => table.boot.exportFormats || ['csv'];

    async function poll(progressId, onUpdate) {
        for (;;) {
            const progress = await table.post(table.endpoints.progress, { id: progressId });

            onUpdate(progress);

            if (progress.status === 'completed' || progress.status === 'failed') return progress;

            await new Promise((resolve) => setTimeout(resolve, 1500));
        }
    }

    function progressBar() {
        const bar = el('div', { class: 'dynamic-table-progress-bar' });
        const label = el('div', { class: 'dynamic-table-progress-label' });

        return {
            node: el('div', { class: 'dynamic-table-progress' }, [el('div', { class: 'dynamic-table-progress-track' }, [bar]), label]),
            update(progress) {
                bar.style.width = `${progress.percent || 0}%`;
                label.textContent = `${progress.percent || 0}% — ${progress.processed || 0} / ${progress.total || 0}`;
            },
        };
    }

    function openExport() {
        let scope = 'view';

        // The server lists what it can write, preferred first.
        let format = formats()[0];

        const scopes = [
            { value: 'view', label: table.t('export.current_view') },
            { value: 'page', label: table.t('export.current_page') },
            { value: 'all', label: table.t('export.all') },
        ];

        if (table.selectionCount() > 0) {
            scopes.unshift({ value: 'selected', label: table.t('export.selected') });
            scope = 'selected';
        }

        const status = el('div', { class: 'dynamic-table-status' });
        const number = (value) => new Intl.NumberFormat(table.boot.locale || undefined).format(value);

        /** Is the table showing less than all of itself? */
        const narrowed = () => Boolean(
            table.state.search
            || Object.keys(table.state.columnSearch || {}).length
            || table.filteredColumns().length,
        );

        /**
         * How many rows the chosen scope will write, said the way that scope
         * makes sense.
         *
         * A page is a range; a filtered view is a count; "every record" has no
         * number the panel can know once filters are on, and a table too large
         * to count has none at all. Saying "0 rows" in either of those cases
         * would be worse than saying what is true.
         */
        function rows() {
            const data = table.data || {};
            const counted = data.counted !== false;

            if (scope === 'selected') return table.t('export.range_rows', { count: number(table.selectionCount()) });

            if (scope === 'page') {
                return table.t('export.range_page', { from: number(data.from || 0), to: number(data.to || 0) });
            }

            // "All records" ignores the filters, so the filtered total is not
            // its answer — but with nothing filtered the two are the same.
            if (scope === 'all' && narrowed()) return table.t('export.range_all');

            if (counted) return table.t('export.range_rows', { count: number(data.total || 0) });

            return data.estimate
                ? table.t('export.range_about', { count: number(data.estimate) })
                : table.t('export.range_all');
        }

        /** The columns the file will carry, in the order the reader put them. */
        const exporting = () => table.visibleColumns().filter((column) => column.exportable !== false);

        /*
         * The panel's one job before the click is to say what the file will
         * contain, so that is what it shows: the rows, the format, and the
         * columns — restated from the two selects underneath rather than left
         * for the reader to work out.
         */
        const headline = el('strong', { class: 'dynamic-table-export-rows' });
        const badge = el('span', { class: 'dynamic-table-export-format' });
        const caption = el('p', { class: 'dynamic-table-export-caption' });
        const columnLine = el('p', { class: 'dynamic-table-export-columns' });

        function sync() {
            const columns = exporting();

            headline.textContent = rows();
            badge.textContent = format.toUpperCase();
            caption.textContent = scopes.find((option) => option.value === scope)?.label || '';
            columnLine.textContent = `${table.t('export.columns', { count: number(columns.length) })}: `
                + columns.map((column) => column.label).join(', ');
        }

        sync();

        const instance = dialog(table, {
            title: table.t('export.title'),
            width: '26rem',
            body: el('div', { class: 'dynamic-table-form' }, [
                el('div', { class: 'dynamic-table-export-summary' }, [
                    el('div', { class: 'dynamic-table-export-headline' }, [headline, badge]),
                    caption,
                    columnLine,
                ]),
                el('label', { class: 'dynamic-table-field' }, [
                    el('span', { class: 'dynamic-table-field-label', text: table.t('export.scope') }),
                    select(table, scopes, scope, (value) => { scope = value; sync(); }),
                ]),
                el('label', { class: 'dynamic-table-field' }, [
                    el('span', { class: 'dynamic-table-field-label', text: table.t('export.format') }),
                    select(table, formats().map((value) => ({ value, label: value.toUpperCase() })), format, (value) => { format = value; sync(); }),
                ]),
                status,
            ]),
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
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
                                    status.replaceChildren(el('p', { class: 'dynamic-table-error', text: done.message || table.t('errors.generic') }));
                                }

                                return;
                            }

                            // Small enough to stream: let the browser download it.
                            download(table.endpoints.export, { scope, format, state: table.serializeState() });
                            instance.close();
                        } catch (error) {
                            status.replaceChildren(el('p', { class: 'dynamic-table-error', text: error.message || table.t('errors.generic') }));
                        }
                    },
                }),
            ]),
        });
    }

    /**
     * The import panel, as the three steps it actually is.
     *
     * Choosing a file, matching its columns and running it are not a list of
     * options — you cannot do the second before the first, and the panel used
     * to say so only by painting nothing until a file arrived. A reader who
     * opened it saw one file input and no idea what else would be asked of
     * them. The rail shows all three from the start, with the two that are not
     * available yet held back rather than hidden.
     *
     * The rail's line is the one the filter panel draws down its groups, on
     * purpose: the two panels are siblings and should look it.
     */
    /**
     * The import panel, as the three steps it actually is.
     *
     * Choosing a file, matching its columns and running it are not a list of
     * options — you cannot do the second before the first, and the panel used
     * to say so only by painting nothing until a file arrived. A reader who
     * opened it saw one file input and no idea what else would be asked of
     * them. The rail shows all three from the start, holding back the two that
     * are not available yet rather than hiding them.
     *
     * The rail's line is the one the filter panel draws down its nested groups,
     * on purpose: the two panels are siblings and should look it.
     */
    function openImport() {
        let analysis = null;
        let mapping = {};
        let mode = 'create';
        let matchBy = '';

        const body = el('div', { class: 'dynamic-table-form' });
        const status = el('div', { class: 'dynamic-table-status' });

        /*
         * Everything sync() touches. A change of mapping must not rebuild the
         * panel: replacing the list would take the focus out of the select the
         * reader is using, one keystroke into it.
         */
        const live = { steps: [], rows: [], problems: null, matchField: null };

        /** The first field the file has a column for, falling back to the first field of all. */
        function defaultMatchBy() {
            const fields = Object.values(analysis?.fields || {});
            const mapped = Object.values(mapping).filter(Boolean);

            return (fields.find((field) => mapped.includes(field.key)) ?? fields[0])?.path ?? '';
        }

        /** Required fields the mapping does not fill. Mirrors ImportManager::missingRequired(). */
        function missingRequired() {
            if (! analysis) return [];

            const mapped = Object.values(mapping).filter(Boolean);

            return Object.values(analysis.fields)
                .filter((field) => field.required && ! mapped.includes(field.key))
                .map((field) => field.label);
        }

        /**
         * Everything standing between this panel and a working import, in the
         * reader's words.
         *
         * Both of these used to be found out by the database, one row at a
         * time, after the import had started.
         */
        function problems() {
            if (! analysis) return [];

            const found = [];

            // Update writes only the columns it is given, so a column it never
            // mentions keeps the value the record already has.
            const missing = mode === 'update' ? [] : missingRequired();

            if (missing.length) found.push(table.t('import.missing', { fields: missing.join(', ') }));

            // Matching on a column the file does not supply finds nothing every
            // time: update skips every row, and upsert inserts a duplicate of
            // every one — which on a file exported from this table fails on the
            // unique key while "Create and update" is selected.
            if (mode !== 'create') {
                const field = Object.values(analysis.fields).find((candidate) => candidate.path === matchBy);
                const mapped = Object.values(mapping).filter(Boolean);

                if (! field || ! mapped.includes(field.key)) {
                    found.push(table.t('import.match_unmapped', { field: field?.label ?? matchBy }));
                }
            }

            return found;
        }

        const fileInput = el('input', {
            type: 'file',
            accept: '.csv,.txt,.xlsx,.xls',
            class: table.classes.input,
            onchange: async () => {
                if (!fileInput.files?.length) return;

                const data = new FormData();
                data.append('file', fileInput.files[0]);
                data.append('table', table.key);

                status.replaceChildren(el('p', { text: table.t('loading') }));

                try {
                    // The server says what was wrong with the file — too large,
                    // the wrong type, a format nothing installed can read — and
                    // upload() keeps that message, supplying its own only where
                    // the server had nothing useful to say.
                    analysis = await table.upload(table.endpoints.import.replace(/\/?$/, '/analyze'), data);
                    mapping = { ...analysis.mapping };

                    /*
                     * A select whose value is not among its options still shows
                     * the first one, so leaving this empty meant the panel
                     * displayed a match field it was not sending. Prefer one
                     * the file actually supplies.
                     */
                    matchBy = defaultMatchBy();
                    status.replaceChildren();
                } catch (error) {
                    analysis = null;
                    status.replaceChildren(el('p', { class: 'dynamic-table-error', text: error.message }));
                }

                paint();
            },
        });

        /**
         * One step of the rail. `state` is done / current / waiting, and it is
         * the only thing that decides how the step is drawn.
         */
        function step(number, title, children) {
            const node = el('li', { class: 'dynamic-table-step' }, [
                el('span', { class: 'dynamic-table-step-marker', 'aria-hidden': 'true', text: String(number) }),
                el('div', { class: 'dynamic-table-step-body' }, [
                    el('h3', { class: 'dynamic-table-step-title', text: title }),
                    ...children.filter(Boolean),
                ]),
            ]);

            node.dataset.number = String(number);

            return node;
        }

        function setState(node, state) {
            node.classList.remove('dynamic-table-step-done', 'dynamic-table-step-current', 'dynamic-table-step-waiting');
            node.classList.add(`dynamic-table-step-${state}`);

            const marker = node.querySelector('.dynamic-table-step-marker');

            if (marker) marker.textContent = state === 'done' ? '✓' : node.dataset.number;
        }

        function fileStep() {
            return step(1, table.t('import.step_file'), [
                fileInput,
                el('p', { class: 'dynamic-table-hint', text: table.t('import.file_hint') }),

                // An aside, not a peer of Start import: anyone who already has
                // a file never needs it, and it used to be the largest control
                // in the panel.
                el('button', {
                    type: 'button',
                    class: 'dynamic-table-quiet-button',
                    text: table.t('import.template'),
                    onclick: () => download(table.endpoints.import.replace(/import$/, 'template'), { format: formats()[0] }),
                }),
            ]);
        }

        function mapStep() {
            if (! analysis) return step(2, table.t('import.step_map'), []);

            const fieldOptions = [
                { value: '', label: table.t('import.ignore') },
                ...Object.values(analysis.fields).map((field) => ({ value: field.key, label: field.label })),
            ];

            live.rows = analysis.headings.map((heading, index) => el('div', { class: 'dynamic-table-mapping-row' }, [
                el('span', { class: 'dynamic-table-mapping-source', text: heading || `#${index + 1}` }),
                el('span', { class: 'dynamic-table-mapping-arrow', 'aria-hidden': 'true', text: '→' }),
                select(table, fieldOptions, mapping[index] || '', (value) => {
                    mapping[index] = value || null;
                    sync();
                }),
            ]));

            /*
             * Match-by is rendered whatever the mode and hidden for Create,
             * rather than added and removed. Rebuilding this row on every
             * change of mode would move the focus out of the mode select.
             */
            live.matchField = el('label', { class: 'dynamic-table-field' }, [
                el('span', { class: 'dynamic-table-field-label', text: table.t('import.match_by') }),
                select(
                    table,
                    Object.values(analysis.fields).map((field) => ({ value: field.path, label: field.label })),
                    matchBy,
                    (value) => { matchBy = value; sync(); },
                ),
            ]);

            live.problems = el('div', { class: 'dynamic-table-error' });

            return step(2, table.t('import.step_map'), [
                el('div', { class: 'dynamic-table-step-fields' }, [
                    el('label', { class: 'dynamic-table-field' }, [
                        el('span', { class: 'dynamic-table-field-label', text: table.t('import.mode') }),
                        select(table, [
                            { value: 'create', label: table.t('import.mode_create') },
                            { value: 'update', label: table.t('import.mode_update') },
                            { value: 'upsert', label: table.t('import.mode_upsert') },
                        ], mode, (value) => { mode = value; sync(); }),
                    ]),
                    live.matchField,
                ]),

                el('div', { class: 'dynamic-table-mapping' }, live.rows),
                live.problems,
            ]);
        }

        function runStep() {
            return step(3, table.t('import.step_run'), [
                analysis?.total
                    ? el('p', { class: 'dynamic-table-hint', text: table.t('import.rows_found', { count: analysis.total }) })
                    : null,

                analysis?.preview?.length
                    ? el('div', { class: 'dynamic-table-preview' }, [
                        el('table', { class: table.classes.table }, [
                            el('thead', {}, [el('tr', {}, analysis.headings.map((heading) => el('th', { text: heading })))]),
                            el('tbody', {}, analysis.preview.map((row) => el('tr', {}, row.map((cell) => el('td', { text: cell ?? '' }))))),
                        ]),
                    ])
                    : null,
            ]);
        }

        /**
         * The same import, rolled back.
         *
         * It answers the question people actually have in front of a mapping
         * screen — "what is this about to do to my data?" — with the numbers
         * the real run would produce, because it *is* the real run. The upload
         * survives it, so Start import is still the next thing to press.
         */
        const previewButton = el('button', {
            type: 'button',
            class: table.classes.button,
            text: table.t('import.preview'),
            onclick: async () => {
                previewButton.disabled = true;
                runButton.disabled = true;

                try {
                    const response = await table.post(table.endpoints.import, {
                        file: analysis.file,
                        token: analysis.token,
                        mapping,
                        mode,
                        matchBy: matchBy || null,
                        dry: true,
                    });

                    status.replaceChildren(...summaryNodes(response));
                } catch (error) {
                    status.replaceChildren(el('p', { class: 'dynamic-table-error', text: error.message }));
                } finally {
                    sync();
                }
            },
        });

        const runButton = el('button', {
            type: 'button',
            class: table.classes.buttonPrimary,
            text: table.t('import.run'),
            onclick: async () => {
                runButton.disabled = true;
                previewButton.disabled = true;

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
                        status.replaceChildren(...summaryNodes(done));
                    } else {
                        status.replaceChildren(...summaryNodes(response));
                    }

                    /*
                     * Both import paths delete the upload once they are done
                     * with it — otherwise every file anyone ever imported stays
                     * on disk, and running the same one twice would import
                     * every row twice. So the panel goes back to step one, with
                     * the summary still on screen. Leaving the button live
                     * pointed it at a file that no longer existed, which is
                     * where "the uploaded file is no longer on the server" came
                     * from.
                     */
                    analysis = null;
                    mapping = {};
                    fileInput.value = '';
                    paint();

                    await table.refresh();
                } catch (error) {
                    status.replaceChildren(el('p', { class: 'dynamic-table-error', text: error.message }));
                    sync();
                }
            },
        });

        /** Everything that changes when the mapping or the mode changes, and nothing else. */
        function sync() {
            const found = problems();
            const required = new Set(
                Object.values(analysis?.fields || {}).filter((field) => field.required).map((field) => field.key),
            );

            live.rows.forEach((row, index) => {
                row.classList.toggle('dynamic-table-mapping-required', required.has(mapping[index]));
            });

            if (live.matchField) live.matchField.hidden = mode === 'create';

            if (live.problems) {
                mount(live.problems, found.map((text) => el('p', { text })));
                live.problems.hidden = ! found.length;
            }

            if (live.steps.length === 3) {
                setState(live.steps[0], analysis ? 'done' : 'current');
                setState(live.steps[1], analysis ? (found.length ? 'current' : 'done') : 'waiting');
                setState(live.steps[2], analysis && ! found.length ? 'current' : 'waiting');
            }

            runButton.disabled = ! analysis || found.length > 0;
            runButton.title = analysis ? found.join(' ') : table.t('import.no_file');
            previewButton.disabled = runButton.disabled;
        }

        function paint() {
            live.rows = [];
            live.problems = null;
            live.matchField = null;
            live.steps = [fileStep(), mapStep(), runStep()];

            body.replaceChildren(el('ol', { class: 'dynamic-table-steps' }, live.steps), status);
            sync();
        }

        paint();

        const instance = dialog(table, {
            title: table.t('import.title'),
            width: '44rem',
            body,
            footer: el('div', { class: 'dynamic-table-modal-actions' }, [
                el('button', { type: 'button', class: table.classes.button, text: table.t('cancel'), onclick: () => instance.close() }),
                previewButton,
                runButton,
            ]),
        });

        function summaryText(result) {
            // A dry run reports the same three numbers in the future tense, so
            // nobody mistakes a preview for a finished import.
            return table.t(result.dry ? 'import.preview_summary' : 'import.summary', {
                created: result.created || 0,
                updated: result.updated || 0,
                failed: result.failed || 0,
            });
        }

        /**
         * What the summary line cannot say: which rows failed, and why.
         *
         * The server already sends the first batch of them back, so a failed
         * import can end with something the reader can act on rather than with
         * a count of rows they now have to go and find themselves.
         */
        function summaryNodes(result) {
            const nodes = [el('p', { class: 'dynamic-table-status-summary', text: summaryText(result) })];
            const errors = result.errors || [];

            if (! errors.length) return nodes;

            nodes.push(el('p', { class: 'dynamic-table-field-label', text: table.t('import.failed_rows') }));

            nodes.push(el('ul', { class: 'dynamic-table-import-errors' }, errors.map((error) => el('li', {
                text: `${error.line}: ${Object.values(error.errors || {}).flat().join(' ')}`,
            }))));

            // Only the first fifty rejections are sent inline. The report is
            // every one of them, so a file that failed in bulk can be fixed
            // from the file rather than from the dialog.
            if (result.report && result.reportToken) {
                nodes.push(el('button', {
                    type: 'button',
                    class: 'dynamic-table-quiet-button',
                    text: table.t('import.errors'),
                    onclick: () => download(
                        table.endpoints.import.replace(/\/?$/, '/errors'),
                        { report: result.report, token: result.reportToken },
                    ),
                }));
            }

            return nodes;
        }
    }

    return {
        open(panel) {
            if (panel === 'export') openExport();
            else openImport();
        },
    };
}

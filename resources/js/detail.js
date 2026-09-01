/**
 * Row detail expander.
 *
 * The panel for a row is fetched the first time it is opened and then kept, so
 * closing and reopening costs nothing while the page is unchanged. A new page
 * of rows throws the cache away — the ids underneath it are gone.
 */

import { el } from './dom.js';

export default function install(table) {
    const cache = new Map();
    const open = new Set();

    function rowFor(id) {
        return table.root.querySelector(`[data-dynamic-table-row="${CSS.escape(String(id))}"]`);
    }

    function span(row) {
        return row.children.length;
    }

    function close(id) {
        open.delete(id);
        table.root.querySelector(`[data-dynamic-table-detail-row="${CSS.escape(String(id))}"]`)?.remove();

        const button = rowFor(id)?.querySelector('[data-dynamic-table-detail]');
        button?.setAttribute('aria-expanded', 'false');
        button?.classList.remove('dynamic-table-expand-open');
    }

    async function show(id) {
        const row = rowFor(id);

        if (! row) return;

        open.add(id);

        const button = row.querySelector('[data-dynamic-table-detail]');
        button?.setAttribute('aria-expanded', 'true');
        button?.classList.add('dynamic-table-expand-open');

        const cell = el('td', { colspan: span(row) });
        const detail = el('tr', { class: 'dynamic-table-detail-row', 'data-dynamic-table-detail-row': id }, [cell]);

        row.after(detail);

        if (cache.has(id)) {
            cell.innerHTML = cache.get(id);

            return;
        }

        cell.append(el('span', { class: 'dynamic-table-detail-loading', text: table.t('loading') }));

        try {
            const response = await table.post(table.endpoints.rowDetail, {
                id,
                state: table.serializeState(),
            });

            cache.set(id, response.html || '');

            // The row may have been collapsed, or the page replaced, while the
            // request was in flight.
            if (detail.isConnected) cell.innerHTML = response.html || '';
        } catch (error) {
            if (detail.isConnected) {
                cell.replaceChildren(el('span', { class: 'dynamic-table-detail-error', text: error.message }));
            }
        }
    }

    function toggle(id) {
        if (open.has(id)) close(id);
        else show(id);
    }

    table.root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dynamic-table-detail]');

        if (! button || ! table.root.contains(button)) return;

        event.preventDefault();
        toggle(button.getAttribute('data-dynamic-table-detail'));
    });

    // Rows were repainted: the expander state and the cached HTML both belong
    // to ids that may no longer be on the page.
    table.on('rows-rendered', () => {
        if (! table.appending) {
            cache.clear();
            open.clear();
        }
    });

    return { toggle, show, close };
}

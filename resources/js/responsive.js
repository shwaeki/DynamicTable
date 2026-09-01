/**
 * Responsive behaviour.
 *
 * Mirrors what DataTables Responsive (used by Yajra) and PowerGrid do: when the
 * table is wider than its container, hide the least important columns and give
 * each row a control that expands a child row listing what was hidden, as
 * label/value pairs. Nothing is lost — it moves.
 *
 * The alternative "cards" mode stacks each row into a labelled block below a
 * breakpoint, and "scroll" needs no JavaScript at all.
 *
 * Which columns go first is decided by priority: lower survives longer. The
 * first column defaults to priority 1, so a row always keeps something that
 * identifies it, and $responsiveFixed pins any others.
 */

import { el } from './dom.js';

export default function install(table) {
    const config = table.boot.responsive;

    if (! config) return {};

    const fixed = new Set(config.fixed || []);
    const expanded = new Set();
    let hidden = [];
    let frame = null;

    const scroller = table.root.querySelector('[data-dynamic-table-scroller]');
    const element = table.root.querySelector('[data-dynamic-table-table]');

    if (! scroller || ! element) return {};

    /* ---------------------------------------------------------------- */
    /* Cards mode                                                        */
    /* ---------------------------------------------------------------- */

    if (config.mode === 'cards') {
        const query = window.matchMedia(`(max-width: ${config.breakpoint}px)`);

        const applyCards = () => table.root.classList.toggle('dynamic-table-responsive-cards', query.matches);

        query.addEventListener('change', applyCards);
        applyCards();

        return { mode: 'cards' };
    }

    /* ---------------------------------------------------------------- */
    /* Collapse mode                                                     */
    /* ---------------------------------------------------------------- */

    const columnsByKey = () => new Map(table.columns.map((column) => [column.key, column]));

    /** Columns currently in the header, in order, ignoring our own control column. */
    function headerCells() {
        return [...element.querySelectorAll('thead tr:first-child > th')]
            .filter((th) => th.hasAttribute('data-dynamic-table-column'));
    }

    /**
     * A column is three cells, not two: the header, the body cells, and — when
     * column search is on — the search cell under the header.
     *
     * Leaving the third one behind did more than show a search box for a column
     * that is no longer there. Every cell of that row still claimed the width of
     * an input, so the table could not shrink; measure() went on hiding columns
     * that were already hidden, and the row it was aligned to had one cell too
     * many. Whoever adds a fourth per-column cell has to add it here too.
     */
    function setColumnHidden(key, isHidden) {
        const escaped = CSS.escape(key);

        for (const cell of element.querySelectorAll(
            `[data-dynamic-table-column="${escaped}"], [data-dynamic-table-search-cell="${escaped}"], [data-dynamic-table-cell="${escaped}"]`,
        )) {
            cell.classList.toggle('dynamic-table-col-hidden', isHidden);
        }
    }

    /**
     * Hide the fewest columns that make the table fit.
     *
     * Measuring beats guessing at breakpoints: a table of three short columns
     * fits on a phone, and one of fifteen does not fit on a laptop.
     */
    function measure() {
        const map = columnsByKey();

        /*
         * A column the reader is searching in is pinned for as long as the
         * search lasts.
         *
         * Collapsing it would take away the only box that can clear it — the
         * table would stay filtered by a criterion with nothing on screen to
         * show for it, and on a phone there is no way to widen the window to
         * get it back. Clearing the box instead would change the result set on
         * a resize, which is worse. Emptying the box makes the column ordinary
         * again on the next measure.
         */
        const searching = new Set(Object.keys(table.state.columnSearch || {}));

        const candidates = headerCells()
            .map((th) => th.getAttribute('data-dynamic-table-column'))
            .filter((key) => ! fixed.has(key) && ! searching.has(key))
            .sort((a, b) => (map.get(b)?.priority ?? 100) - (map.get(a)?.priority ?? 100));

        // Start from everything visible, then remove until it fits.
        for (const key of candidates) setColumnHidden(key, false);

        hidden = [];

        const overflowing = () => element.scrollWidth > scroller.clientWidth + 1;

        for (const key of candidates) {
            if (! overflowing()) break;

            setColumnHidden(key, true);
            hidden.push(key);
        }

        // Keep the child rows in the column order the user sees, not the order
        // we happened to hide them in.
        const order = headerCells().map((th) => th.getAttribute('data-dynamic-table-column'));
        hidden.sort((a, b) => order.indexOf(a) - order.indexOf(b));

        table.root.classList.toggle('dynamic-table-has-collapsed', hidden.length > 0);
        renderControls();
        refreshOpenChildren();
    }

    /* ---------------------------------------------------------------- */
    /* The expand control and the child row                              */
    /* ---------------------------------------------------------------- */

    function renderControls() {
        const needed = hidden.length > 0;
        const headRow = element.querySelector('thead tr:first-child');
        const searchRow = element.querySelector('thead [data-dynamic-table-search-row]');

        if (! headRow) return;

        let headControl = headRow.querySelector('.dynamic-table-control-cell');

        if (needed && ! headControl) {
            headControl = el('th', {
                class: 'dynamic-table-control-cell',
                scope: 'col',
                'aria-label': table.t('responsive.details'),
            });
            headRow.prepend(headControl);

            if (searchRow) searchRow.prepend(el('th', { class: 'dynamic-table-control-cell' }));
        } else if (! needed && headControl) {
            headControl.remove();
            searchRow?.querySelector('.dynamic-table-control-cell')?.remove();
        }

        for (const row of element.querySelectorAll('tbody > tr[data-dynamic-table-row]')) {
            const id = row.getAttribute('data-dynamic-table-row');
            let cell = row.querySelector('.dynamic-table-control-cell');

            if (! needed) {
                cell?.remove();
                closeChild(row);

                continue;
            }

            if (cell) continue;

            const open = expanded.has(id);

            cell = el('td', { class: 'dynamic-table-control-cell' }, [
                el('button', {
                    type: 'button',
                    class: 'dynamic-table-control',
                    'aria-expanded': String(open),
                    'aria-label': table.t(open ? 'responsive.hide_details' : 'responsive.details'),
                    text: open ? '−' : '+',
                    onclick: () => toggle(row),
                }),
            ]);

            row.prepend(cell);
        }
    }

    function toggle(row) {
        const id = row.getAttribute('data-dynamic-table-row');

        if (expanded.has(id)) {
            expanded.delete(id);
            closeChild(row);
        } else {
            expanded.add(id);
            openChild(row);
        }

        const button = row.querySelector('.dynamic-table-control');

        if (button) {
            const open = expanded.has(id);
            button.textContent = open ? '−' : '+';
            button.setAttribute('aria-expanded', String(open));
            button.setAttribute('aria-label', table.t(open ? 'responsive.hide_details' : 'responsive.details'));
        }
    }

    function closeChild(row) {
        const next = row.nextElementSibling;

        if (next?.classList.contains('dynamic-table-child')) next.remove();
    }

    function openChild(row) {
        closeChild(row);

        if (! hidden.length) return;

        const map = columnsByKey();
        const list = el('dl', { class: 'dynamic-table-child-list' });

        for (const key of hidden) {
            const source = row.querySelector(`[data-dynamic-table-cell="${CSS.escape(key)}"]`);

            if (! source) continue;

            const value = el('dd');

            // Clone so the child row shows exactly what the cell shows —
            // badges, thumbnails, links and all.
            for (const node of source.childNodes) value.append(node.cloneNode(true));

            list.append(el('dt', { text: map.get(key)?.label ?? key }), value);
        }

        const span = row.children.length;

        row.after(el('tr', { class: 'dynamic-table-child' }, [
            el('td', { colspan: span }, [list]),
        ]));
    }

    function refreshOpenChildren() {
        for (const row of element.querySelectorAll('tbody > tr[data-dynamic-table-row]')) {
            if (expanded.has(row.getAttribute('data-dynamic-table-row'))) openChild(row);
            else closeChild(row);
        }
    }

    /* ---------------------------------------------------------------- */

    const schedule = () => {
        if (frame) cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            frame = null;
            measure();
        });
    };

    if (typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(schedule).observe(scroller);
    } else {
        window.addEventListener('resize', schedule);
    }

    // Re-measure whenever the shape of the table changes.
    table.on('rows-rendered', schedule);
    table.on('header-rendered', () => {
        expanded.clear();
        schedule();
    });

    schedule();

    return {
        mode: 'collapse',
        measure,
        get hidden() {
            return [...hidden];
        },
    };
}

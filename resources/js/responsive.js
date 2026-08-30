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

    const scroller = table.root.querySelector('[data-dt-scroller]');
    const element = table.root.querySelector('[data-dt-table]');

    if (! scroller || ! element) return {};

    /* ---------------------------------------------------------------- */
    /* Cards mode                                                        */
    /* ---------------------------------------------------------------- */

    if (config.mode === 'cards') {
        const query = window.matchMedia(`(max-width: ${config.breakpoint}px)`);

        const applyCards = () => table.root.classList.toggle('dt-responsive-cards', query.matches);

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
            .filter((th) => th.hasAttribute('data-dt-column'));
    }

    function setColumnHidden(key, isHidden) {
        for (const cell of element.querySelectorAll(
            `[data-dt-column="${CSS.escape(key)}"], [data-dt-cell="${CSS.escape(key)}"]`,
        )) {
            cell.classList.toggle('dt-col-hidden', isHidden);
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

        const candidates = headerCells()
            .map((th) => th.getAttribute('data-dt-column'))
            .filter((key) => ! fixed.has(key))
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
        const order = headerCells().map((th) => th.getAttribute('data-dt-column'));
        hidden.sort((a, b) => order.indexOf(a) - order.indexOf(b));

        table.root.classList.toggle('dt-has-collapsed', hidden.length > 0);
        renderControls();
        refreshOpenChildren();
    }

    /* ---------------------------------------------------------------- */
    /* The expand control and the child row                              */
    /* ---------------------------------------------------------------- */

    function renderControls() {
        const needed = hidden.length > 0;
        const headRow = element.querySelector('thead tr:first-child');
        const filterRow = element.querySelector('thead .dt-filter-row');

        if (! headRow) return;

        let headControl = headRow.querySelector('.dt-control-cell');

        if (needed && ! headControl) {
            headControl = el('th', {
                class: 'dt-control-cell',
                scope: 'col',
                'aria-label': table.t('responsive.details'),
            });
            headRow.prepend(headControl);

            if (filterRow) filterRow.prepend(el('th', { class: 'dt-control-cell' }));
        } else if (! needed && headControl) {
            headControl.remove();
            filterRow?.querySelector('.dt-control-cell')?.remove();
        }

        for (const row of element.querySelectorAll('tbody > tr[data-dt-row]')) {
            const id = row.getAttribute('data-dt-row');
            let cell = row.querySelector('.dt-control-cell');

            if (! needed) {
                cell?.remove();
                closeChild(row);

                continue;
            }

            if (cell) continue;

            const open = expanded.has(id);

            cell = el('td', { class: 'dt-control-cell' }, [
                el('button', {
                    type: 'button',
                    class: 'dt-control',
                    'aria-expanded': String(open),
                    'aria-label': table.t('responsive.details'),
                    text: open ? '−' : '+',
                    onclick: () => toggle(row),
                }),
            ]);

            row.prepend(cell);
        }
    }

    function toggle(row) {
        const id = row.getAttribute('data-dt-row');

        if (expanded.has(id)) {
            expanded.delete(id);
            closeChild(row);
        } else {
            expanded.add(id);
            openChild(row);
        }

        const button = row.querySelector('.dt-control');

        if (button) {
            const open = expanded.has(id);
            button.textContent = open ? '−' : '+';
            button.setAttribute('aria-expanded', String(open));
        }
    }

    function closeChild(row) {
        const next = row.nextElementSibling;

        if (next?.classList.contains('dt-child')) next.remove();
    }

    function openChild(row) {
        closeChild(row);

        if (! hidden.length) return;

        const map = columnsByKey();
        const list = el('dl', { class: 'dt-child-list' });

        for (const key of hidden) {
            const source = row.querySelector(`[data-dt-cell="${CSS.escape(key)}"]`);

            if (! source) continue;

            const value = el('dd');

            // Clone so the child row shows exactly what the cell shows —
            // badges, thumbnails, links and all.
            for (const node of source.childNodes) value.append(node.cloneNode(true));

            list.append(el('dt', { text: map.get(key)?.label ?? key }), value);
        }

        const span = row.children.length;

        row.after(el('tr', { class: 'dt-child' }, [
            el('td', { colspan: span }, [list]),
        ]));
    }

    function refreshOpenChildren() {
        for (const row of element.querySelectorAll('tbody > tr[data-dt-row]')) {
            if (expanded.has(row.getAttribute('data-dt-row'))) openChild(row);
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

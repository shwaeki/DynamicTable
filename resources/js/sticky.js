/**
 * Sticky (frozen) columns.
 *
 * CSS does the sticking; this module only measures. Each frozen column has to
 * know how wide everything before it is, and that changes with the data, the
 * column picker, resizing and the viewport — so the offsets are recomputed
 * rather than declared.
 *
 * Offsets are written as logical inset values, so a frozen column freezes on
 * the correct side in RTL without a second code path.
 */

export default function install(table) {
    let frame = null;

    function cells() {
        return table.root.querySelectorAll('[data-dt-sticky]');
    }

    function measure() {
        frame = null;

        const head = table.root.querySelector('[data-dt-table] thead tr');

        if (! head) return;

        // Leading structural cells (expander, checkbox) are frozen too — a
        // checkbox that scrolls away from its row is worse than useless.
        const offsets = new Map();
        let offset = 0;

        for (const th of head.children) {
            const key = th.getAttribute('data-dt-column');
            const structural = th.classList.contains('dt-select-cell') || th.classList.contains('dt-expand-cell');

            if (! structural && ! th.hasAttribute('data-dt-sticky')) break;

            if (key) offsets.set(key, offset);
            th.style.insetInlineStart = `${offset}px`;
            th.classList.add('dt-sticky-cell');
            th.toggleAttribute('data-dt-sticky-last', false);

            offset += th.getBoundingClientRect().width;
        }

        // The last frozen header carries the divider, so the shadow sits at the
        // boundary rather than between every frozen column.
        const frozen = [...head.children].filter((th) => th.classList.contains('dt-sticky-cell'));
        frozen.at(-1)?.toggleAttribute('data-dt-sticky-last', true);

        // The column-search row is part of the header, cell for cell, so it
        // freezes with it — a search box that slides out from under its own
        // column is the same bug as a header that scrolls away.
        const searchRow = table.root.querySelector('[data-dt-table] [data-dt-search-row]');

        if (searchRow) {
            [...searchRow.children].forEach((th, index) => {
                const source = head.children[index];

                if (index >= frozen.length || ! source) {
                    th.classList.remove('dt-sticky-cell');
                    th.toggleAttribute('data-dt-sticky-last', false);
                    th.style.insetInlineStart = '';

                    return;
                }

                th.style.insetInlineStart = source.style.insetInlineStart;
                th.classList.add('dt-sticky-cell');
                th.toggleAttribute('data-dt-sticky-last', index === frozen.length - 1);
            });
        }

        for (const row of table.root.querySelectorAll('[data-dt-body] > tr')) {
            let position = 0;

            for (const td of row.children) {
                const key = td.getAttribute('data-dt-cell');
                const structural = td.classList.contains('dt-select-cell') || td.classList.contains('dt-expand-cell');

                if (! structural && ! td.hasAttribute('data-dt-sticky')) break;

                td.style.insetInlineStart = `${key !== null ? (offsets.get(key) ?? position) : position}px`;
                td.classList.add('dt-sticky-cell');
                td.toggleAttribute('data-dt-sticky-last', false);

                position += td.getBoundingClientRect().width;
            }

            [...row.children].filter((td) => td.classList.contains('dt-sticky-cell'))
                .at(-1)?.toggleAttribute('data-dt-sticky-last', true);
        }

        // Row actions freeze against the opposite edge, so the buttons stay
        // reachable however far the table is scrolled.
        if (table.boot.stickyActions) {
            for (const cell of table.root.querySelectorAll('.dt-row-actions-cell')) {
                cell.style.insetInlineEnd = '0px';
                cell.classList.add('dt-sticky-cell', 'dt-sticky-end');
            }
        }

        table.root.classList.toggle('dt-has-sticky', cells().length > 0);
    }

    function schedule() {
        if (frame !== null) return;

        frame = requestAnimationFrame(measure);
    }

    table.on('rows-rendered', schedule);
    table.on('header-rendered', schedule);
    table.on('columns-changed', schedule);
    table.on('updated', schedule);

    window.addEventListener('resize', schedule);

    if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(schedule);
        const scroller = table.root.querySelector('[data-dt-scroller]');

        if (scroller) observer.observe(scroller);
    }

    schedule();

    return { measure: schedule };
}

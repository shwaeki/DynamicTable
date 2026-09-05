/**
 * Pinned rows.
 *
 * A pin changes the ORDER BY of the next query, so pressing one has to refetch
 * — the pinned row belongs at the top of page 1, which may not be where it
 * currently is. The star is filled optimistically so the press feels answered,
 * and the refetch corrects it if the server disagrees.
 */

export default function install(table) {
    let pinned = new Set((table.boot.pinned || []).map(String));

    function mark() {
        for (const button of table.root.querySelectorAll('[data-dynamic-table-pin]')) {
            const id = button.getAttribute('data-dynamic-table-pin');
            const on = pinned.has(String(id));

            button.classList.toggle('dynamic-table-pin-on', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.setAttribute('title', table.t(on ? 'unpin_row' : 'pin_row'));
            button.textContent = on ? '★' : '☆';
        }
    }

    table.root.addEventListener('click', async (event) => {
        const button = event.target.closest?.('[data-dynamic-table-pin]');

        if (! button || ! table.root.contains(button)) return;

        // Rows can be links; a star inside one must not follow it.
        event.preventDefault();
        event.stopPropagation();

        const id = String(button.getAttribute('data-dynamic-table-pin'));

        pinned.has(id) ? pinned.delete(id) : pinned.add(id);
        mark();

        try {
            const response = await table.post(table.endpoints.pin, { id });

            pinned = new Set((response.pinned || []).map(String));
            table.boot.pinned = [...pinned];

            // The order changed, and the first page is where a pin is meant to
            // show. Anything else would pin a row somewhere the reader cannot
            // see it.
            await table.refresh({ resetPage: true });
        } catch (error) {
            table.alert(error.message, 'error');
            pinned = new Set((table.boot.pinned || []).map(String));
        }

        mark();
    });

    table.on('rows-rendered', mark);
    mark();
}

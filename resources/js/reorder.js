/**
 * Dragging a row to a new position.
 *
 * The whole interaction is one HTML5 drag between two rows of one page, and
 * the server is told the resulting order of ids rather than "row 4 moved to
 * 7" — an order is unambiguous, and it survives the row having moved twice
 * before the request lands.
 *
 * Dragging is possible only while the table is sorted by the position column.
 * Under any other sort, dropping a row between two others describes a place
 * the table is not showing, so the handles are hidden rather than left there
 * to do something surprising.
 */

export default function install(table) {
    const column = table.boot.reorderable;

    if (! column) return;

    let dragging = null;

    function sortedByPosition() {
        return table.state.sort?.[0]?.field === column;
    }

    function rows() {
        return [...table.root.querySelectorAll('[data-dynamic-table-row]')];
    }

    /**
     * Show or hide the handles for the sort the table currently has.
     *
     * Called after every repaint, because the rows are new elements each time
     * and the sort may have changed with them.
     */
    function sync() {
        const enabled = sortedByPosition();

        table.root.classList.toggle('dynamic-table-reorderable', enabled);

        for (const row of rows()) {
            row.draggable = enabled;

            const handle = row.querySelector('[data-dynamic-table-reorder-handle]');

            if (handle) {
                handle.hidden = ! enabled;
                handle.title = enabled ? table.t('reorder_row') : table.t('reorder_unavailable');
            }
        }
    }

    function rowFrom(target) {
        const row = target.closest?.('[data-dynamic-table-row]');

        return row && table.root.contains(row) ? row : null;
    }

    table.root.addEventListener('dragstart', (event) => {
        if (! sortedByPosition()) return;

        // Only from the handle. A row that starts dragging because someone
        // swiped across a cell to select text is a table that fights back.
        if (! event.target.closest?.('[data-dynamic-table-reorder-handle]')) {
            event.preventDefault();

            return;
        }

        dragging = rowFrom(event.target);

        if (! dragging) return;

        dragging.classList.add('dynamic-table-row-dragging');
        event.dataTransfer.effectAllowed = 'move';

        // Firefox starts no drag at all without data on the transfer.
        event.dataTransfer.setData('text/plain', dragging.dataset.dynamicTableRow ?? '');
    });

    table.root.addEventListener('dragover', (event) => {
        if (! dragging) return;

        const over = rowFrom(event.target);

        if (! over || over === dragging) return;

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        // Which half of the row the pointer is in decides which side of it the
        // dragged row lands on, so a drop never depends on hitting a 1px gap.
        const box = over.getBoundingClientRect();
        const after = event.clientY > box.top + (box.height / 2);

        after ? over.after(dragging) : over.before(dragging);
    });

    table.root.addEventListener('dragend', () => {
        if (! dragging) return;

        dragging.classList.remove('dynamic-table-row-dragging');
        dragging = null;
    });

    table.root.addEventListener('drop', async (event) => {
        if (! dragging) return;

        event.preventDefault();

        const moved = dragging;

        moved.classList.remove('dynamic-table-row-dragging');
        dragging = null;

        await save(moved);
    });

    async function save(moved) {
        const order = rows().map((row) => row.dataset.dynamicTableRow);

        table.setLoading(true);

        try {
            await table.post(table.endpoints.reorder, {
                ids: order,
                state: table.serializeState(),
            });

            moved.classList.add('dynamic-table-row-moved');
            setTimeout(() => moved.classList.remove('dynamic-table-row-moved'), 1200);

            table.emit('rows-reordered', order);
        } catch (error) {
            // The rows on screen are now in an order the database does not
            // agree with, and no amount of local repair makes them agree — so
            // the server's own answer is fetched back.
            table.alert(error.message, 'error');
            await table.refresh({ silent: true });
        } finally {
            table.setLoading(false);
        }
    }

    /*
     * The same move, from the keyboard.
     *
     * A grip you can only drag is a grip half the people who need it cannot
     * use, and dragging a row is fiddly with a trackpad even for those who can.
     * Alt with an arrow key is what the column picker already uses for exactly
     * this, so it is the gesture people will try.
     */
    table.root.addEventListener('keydown', async (event) => {
        if (! event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;

        const handle = event.target.closest?.('[data-dynamic-table-reorder-handle]');

        if (! handle || ! table.root.contains(handle) || ! sortedByPosition()) return;

        const row = rowFrom(handle);
        const sibling = event.key === 'ArrowUp' ? row?.previousElementSibling : row?.nextElementSibling;

        // Only past another row: a group heading or a detail panel is not
        // somewhere a row can go.
        if (! row || ! sibling?.matches('[data-dynamic-table-row]')) return;

        event.preventDefault();

        event.key === 'ArrowUp' ? sibling.before(row) : sibling.after(row);

        // The row moved, so its handle moved with it; the focus has to follow
        // or the next press acts on whatever is now under the cursor.
        handle.focus();

        await save(row);
    });

    table.on('rows-rendered', sync);
    sync();

    // The header menu changes the sort without a repaint of the handles, and
    // "updated" is the one event every state change ends with.
    table.on('updated', sync);
}

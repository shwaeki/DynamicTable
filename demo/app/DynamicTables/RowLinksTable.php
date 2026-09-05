<?php

namespace App\DynamicTables;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * A list you click through to a record.
 *
 * The first cell becomes a **real link**, and that word is doing work: it is
 * focusable, it is announced as a link, a middle-click opens a tab, and "copy
 * link address" works. A click handler does none of that, which is why the
 * handler here is only the convenience on top — it makes the rest of the row
 * follow the same URL, and a modified click opens a tab the way a link would.
 *
 * Try it: tab to the first cell, middle-click a row, select text in a cell and
 * let go — the last one does not navigate, because ending a selection is not a
 * click on the row.
 */
class RowLinksTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [Feature::HEADER_MENU, Feature::SORTING, Feature::ROW_ACTIONS];

    /**
     * "single", "double" or "none".
     *
     * Null — the default — means "double" on a table with inline editing and
     * "single" everywhere else, because double-click already opens the editor
     * there.
     */
    protected ?string $rowClick = 'single';

    protected array $defaultSort = ['placed_at' => 'desc'];

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'status',
            'total' => ['format' => 'currency:USD', 'align' => 'end'],
            'placed_at' => 'Placed',
        ];
    }

    /** Return null for a row that should not be a link. */
    public function rowUrl(Model $record): ?string
    {
        return route('examples.show', 'row-links').'#'.$record->reference;
    }
}

<?php

namespace App\DynamicTables;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * A chevron on every row opens a panel underneath it.
 *
 * The panel is fetched on demand: it is usually the most expensive thing on
 * the screen and most rows are never opened. The record is re-fetched through
 * the table's own base query, so a detail can never be read for a row this
 * table would not show.
 */
class RowDetailTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [Feature::ROW_DETAIL];

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

    /** Return a string, an HtmlString, or — as here — a Blade view. */
    public function rowDetail(Model $record): mixed
    {
        return view('partials.order-detail', [
            'order' => $record->load('items.product', 'customer'),
        ]);
    }
}

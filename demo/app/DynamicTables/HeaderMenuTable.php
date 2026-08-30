<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The column header menu, as in Dynamics 365 grids.
 *
 * Hover a header and click the chevron: sort either way (labelled by type —
 * A→Z, 1→9, oldest→newest), group by the column, filter on it, set its width,
 * move it one place, or hide it. Widths can also be dragged straight from the
 * header edge, Excel-style.
 *
 * Nothing here is configured: the menu offers exactly the actions the enabled
 * features support, and every item writes to the same table state that a saved
 * view stores and an export follows.
 */
class HeaderMenuTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [
        'grouping',
        'column_picker',
        'column_reordering',
        'column_resizing',
    ];

    /*
     * No responsive collapsing here.
     *
     * Collapsing hides the columns that do not fit, which is exactly what this
     * example is about arranging by hand — the two would be fighting over the
     * same thing in front of the reader.
     */
    protected ?string $responsive = 'none';

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'reference' => 'Order',
            'customer.name' => 'Customer',
            'status',
            'total' => ['format' => 'currency:USD', 'align' => 'end'],
            'items_count' => 'Items',
            'placed_at' => 'Placed',
        ];
    }
}

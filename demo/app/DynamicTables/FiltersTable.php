<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The advanced filter builder, with nothing configured to enable it.
 *
 * Try:  ( Status = Shipped  OR  Status = Delivered )  AND  Total > 500
 */
class FiltersTable extends DynamicTable
{
    protected string $model = Order::class;

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'status',
            'total' => ['format' => 'currency:USD'],
            'items_count' => 'Items',
            'placed_at',
        ];
    }
}

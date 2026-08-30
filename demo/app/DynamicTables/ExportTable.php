<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Export respects the active view: its visible columns, in their chosen order,
 * with their formatting — so "Customer" exports the customer's name, not
 * customer_id.
 *
 * Filter or reorder first, then export, and compare the file. Past 5,000 rows
 * (configurable) the export queues itself and the UI shows progress.
 */
class ExportTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = ['export', 'column_picker', 'column_reordering'];

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'customer.country' => 'Country',
            'status',
            'total' => ['format' => 'currency:USD'],
            'items_count' => 'Items',
            'placed_at',
        ];
    }
}

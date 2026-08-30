<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Everything on this page is server-side.
 *
 * The developer panel under the table reports the response time, peak memory
 * and which relations were eager loaded. Change the page size from 10 to 100
 * and watch the query count stay the same: 2 + one per relation.
 *
 * The panel is suppressed in production regardless of configuration.
 */
class LargeDatasetTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = ['export', 'column_picker', 'url_state'];

    protected ?int $perPage = 50;

    protected array $perPageOptions = [10, 50, 100, 200];

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'user.name' => 'Sales rep',
            'status',
            'total' => ['format' => 'currency:USD'],
            'placed_at',
        ];
    }
}

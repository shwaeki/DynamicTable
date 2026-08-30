<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Scroll to the bottom and the next page appends itself.
 *
 * This is a presentation choice on top of ordinary server-side paging: the
 * same endpoint, the same LIMIT, appended instead of replaced. Nothing loads
 * "everything", and because the pages are stitched together no COUNT(*) is
 * run — the footer reports the range rather than an invented total.
 */
class InfiniteScrollTable extends DynamicTable
{
    protected string $model = Order::class;

    protected string $pagination = 'infinite';

    protected ?int $perPage = 25;

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
}

<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Responsive "collapse" mode — the default.
 *
 * Narrow the window: columns that no longer fit are hidden and a + control
 * appears on each row, expanding a child row that lists them as label/value
 * pairs. This is the behaviour DataTables Responsive gives Yajra tables, and
 * what PowerGrid's responsive feature does.
 *
 * Which columns go first is decided by priority — lower survives longer. The
 * first column defaults to priority 1 so a row always keeps something that
 * identifies it, and $responsiveFixed pins any others.
 */
class ResponsiveTable extends DynamicTable
{
    protected string $model = Order::class;

    protected ?string $responsive = 'collapse';

    /** Reference identifies the row; status is the thing people scan for. */
    protected array $responsiveFixed = ['reference', 'status'];

    protected function columns(): array
    {
        return [
            'reference' => ['label' => 'Order'],
            'status',
            'customer.name' => ['label' => 'Customer', 'priority' => 2],
            'total' => ['format' => 'currency:USD', 'align' => 'end', 'priority' => 3],
            'items_count' => ['label' => 'Items', 'priority' => 40],
            'user.name' => ['label' => 'Sales rep', 'priority' => 50],
            'placed_at' => ['label' => 'Placed', 'priority' => 60],
            'shipped_at' => ['label' => 'Shipped', 'priority' => 70],
        ];
    }
}

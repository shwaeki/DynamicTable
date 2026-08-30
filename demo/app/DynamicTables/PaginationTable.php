<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/** Custom page sizes. Pagination is always server-side. */
class PaginationTable extends DynamicTable
{
    protected string $model = Order::class;

    protected ?int $perPage = 5;

    protected array $perPageOptions = [5, 15, 30, 60];

    protected function columns(): array
    {
        return ['reference', 'customer.name' => 'Customer', 'status', 'total' => ['format' => 'currency:USD'], 'placed_at'];
    }
}

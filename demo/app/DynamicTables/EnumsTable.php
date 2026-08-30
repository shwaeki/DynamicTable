<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Backed enums need no configuration at all.
 *
 * The cast is read from the model, so the column renders as a badge, the filter
 * offers exactly the enum's cases, and the inline editor is a select. Because
 * OrderStatus defines label(), "pending" displays as "Awaiting payment"
 * everywhere — including in exports.
 */
class EnumsTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = ['inline_edit'];

    protected function columns(): array
    {
        return ['reference', 'customer.name' => 'Customer', 'status', 'total', 'placed_at'];
    }
}

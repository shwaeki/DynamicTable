<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Four relationship columns across three relations. Turn the developer panel on
 * and you will see this page costs 2 queries plus one per relation, whatever
 * the page size.
 */
class RelationshipsTable extends DynamicTable
{
    protected string $model = Order::class;

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'customer.country' => 'Country',
            'user.name' => 'Sales rep',
            'invoice.number' => 'Invoice',
            'status',
            'total' => ['format' => 'currency:USD'],
        ];
    }
}

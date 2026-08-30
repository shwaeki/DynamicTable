<?php

namespace App\DynamicTables;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Customising the base query.
 *
 * query() receives and returns an ordinary Eloquent builder, and runs before
 * anything the user can influence. $scopes applies named model scopes, and
 * $with eager loads relations you need for a render closure even though no
 * column references them directly.
 */
class ScopedQueryTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $scopes = ['shipped'];

    protected array $with = ['invoice'];

    public function query(Builder $query): Builder
    {
        return $query->where('total', '>', 250);
    }

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'total' => ['format' => 'currency:USD'],
            'shipped_at' => 'Shipped',
            'invoice' => [
                'label' => 'Invoice',
                'render' => fn ($value, $order): string => $order->invoice?->number ?? '—',
            ],
        ];
    }
}

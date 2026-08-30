<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Filter values that carry their own counts: "Shipped (1,204)".
 *
 * Properly faceted — the current search and filters apply to the counts,
 * except any condition already set on that same column. Otherwise choosing
 * "Shipped" would report every other status as zero, which is the classic
 * faceting mistake.
 *
 * Opt in per column: it is one extra grouped query, run only when a filter
 * dropdown is actually opened.
 */
class FacetedFiltersTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [Feature::FACETS];

    protected array $facets = ['status'];

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

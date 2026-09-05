<?php

namespace App\DynamicTables;

use App\Models\Customer;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * How many, how much, whether any — over a plural relation.
 *
 * A customer has many orders, and "customers with more than five" or "lifetime
 * spend over 1,000" are the questions people actually bring to a customer list.
 * Neither is a column on the model, and both are one correlated subselect: no
 * join, no GROUP BY on the outer query, no duplicated rows.
 *
 * The spelling is Eloquent's own, because these compile to withCount(),
 * withExists() and withAggregate() — the name written here is the name the
 * query answers with.
 */
class RelationAggregatesTable extends DynamicTable
{
    protected string $model = Customer::class;

    protected array $features = [Feature::COLUMN_PICKER, Feature::SORTING];

    /** Sortable like any other column: the alias the subselect was given. */
    protected array $defaultSort = ['orders_sum_total' => 'desc'];

    protected function columns(): array
    {
        return [
            'name',
            'country',
            'company.name' => 'Company',

            // count(*) over the relation.
            'orders_count' => ['label' => 'Orders', 'align' => 'end'],

            // sum(orders.total) — numeric columns only, and zero when there
            // are no rows, so "spend under 100" includes a customer who has
            // never ordered.
            'orders_sum_total' => ['label' => 'Lifetime spend', 'format' => 'currency:USD', 'align' => 'end'],

            // min and max work on anything that orders, dates included; over no
            // rows they are genuinely nothing rather than zero.
            'orders_max_total' => ['label' => 'Biggest order', 'format' => 'currency:USD', 'align' => 'end'],
            'orders_max_placed_at' => ['label' => 'Last ordered'],

            // A yes/no that costs an EXISTS rather than a count.
            'orders_exists' => ['label' => 'Has ordered', 'align' => 'center'],
        ];
    }
}

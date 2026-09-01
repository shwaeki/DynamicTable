<?php

namespace App\DynamicTables;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Filter controls that live in your own page, not in the table's toolbar.
 *
 * An admin screen usually has a filter bar above the grid already — styled with
 * the host application's own form classes, laid out in its own columns. This is
 * how that bar drives the table: each control carries the parameter it sets and
 * the table it sets it on, and `$paramFilters` says what each parameter does to
 * the query. No JavaScript, no listener, no controller changes.
 */
class ParamFiltersTable extends DynamicTable
{
    protected string $model = Order::class;

    /*
     * The key the markup points at. Without one it is derived from the class
     * name, which works but leaves the Blade coupled to a class name — so a
     * table with an outside filter bar is worth naming.
     */
    protected ?string $tableKey = 'demo_orders';

    protected array $features = ['export', 'print'];

    protected int $relationDepth = 2;

    /**
     * Every shape a parameter filter can take.
     *
     * The name on the left is what the control sends; what is on the right is
     * what it does. A filter is skipped entirely when its parameter arrives
     * empty, so an untouched bar costs nothing.
     */
    protected array $paramFilters = [
        // Same name as the column: where('status', $value).
        'status',

        // The control is called "country"; the column lives on the relation.
        'country' => ['column' => 'customer.country'],

        // Any comparison the operator vocabulary knows.
        'min_total' => ['column' => 'total', 'operator' => 'greater_or_equal'],

        // A period picker. "placed_period" also accepts placed_from/placed_to
        // when the reader chooses a custom range, because a picker named
        // <thing>_period is the shape everyone writes.
        'placed_period' => ['column' => 'placed_at', 'operator' => 'period'],
    ];

    /**
     * The query the parameters narrow.
     *
     * This runs first and owns what the table is allowed to show at all — in a
     * real admin screen it is where an agent is limited to their own records.
     * The parameter filters are applied on top of it, so no control can widen
     * what this returns.
     */
    public function query(Builder $query): Builder
    {
        return $query->where('total', '>', 0);
    }

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'customer.country' => 'Country',
            'status',
            'total' => ['format' => 'currency:USD'],
            'placed_at',
        ];
    }
}

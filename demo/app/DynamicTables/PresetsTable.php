<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Developer-defined views. They appear in the Views menu next to saved ones,
 * are not editable by users, and one of them can be the default.
 */
class PresetsTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = ['saved_views'];

    protected function columns(): array
    {
        return ['reference', 'customer.name' => 'Customer', 'status', 'total', 'placed_at'];
    }

    public function presets(): array
    {
        return [
            'needs_attention' => [
                'name' => 'Needs attention',
                'default' => true,
                'sort' => [['field' => 'placed_at', 'direction' => 'asc']],
                'filters' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'status', 'operator' => 'equals', 'value' => 'pending'],
                    ],
                ],
            ],
            'big_orders' => [
                'name' => 'Orders over $1,000',
                'sort' => [['field' => 'total', 'direction' => 'desc']],
                'filters' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'total', 'operator' => 'greater_than', 'value' => 1000],
                    ],
                ],
            ],
            'shipped_this_month' => [
                'name' => 'Shipped this month',
                'filters' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'shipped_at', 'operator' => 'this_month'],
                    ],
                ],
            ],

            /*
             * A condition comparing two columns rather than a column and a
             * value: {"field": …} in place of the value.
             *
             * This is how the data-quality questions get asked — rows that
             * contradict themselves — and it compiles to whereColumn, so it
             * costs nothing extra. Both sides have to be real columns on the
             * table's own row and of the same type family; a relation path, an
             * aggregate or a mismatched type is refused.
             */
            'impossible_dates' => [
                'name' => 'Shipped before it was placed',
                'sort' => [['field' => 'placed_at', 'direction' => 'desc']],
                'filters' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'shipped_at', 'operator' => 'before', 'value' => ['field' => 'placed_at']],
                    ],
                ],
            ],
        ];
    }
}

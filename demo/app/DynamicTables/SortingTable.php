<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Click a header to sort; shift-click to add a second and third sort column.
 * "Category" sorts through a correlated subquery, not a join, so rows are
 * never duplicated.
 */
class SortingTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $defaultSort = ['price' => 'desc'];

    protected function columns(): array
    {
        return [
            'name',
            'sku',
            'category.name' => 'Category',
            'price' => ['format' => 'currency:USD'],
            'stock',
            'released_at',
        ];
    }
}

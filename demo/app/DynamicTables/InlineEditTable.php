<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Double-click a cell to edit it. Enter saves and moves down, Tab saves and
 * moves across, Escape cancels.
 *
 * The control matches the column type: a select for the enum and the boolean,
 * a number input for price and stock, a date picker for the release date.
 */
class InlineEditTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['inline_edit'];

    protected function columns(): array
    {
        return [
            'name',
            'sku' => ['editable' => false],
            'category.name' => 'Category',
            'status',
            'price' => ['align' => 'end'],
            'stock' => ['align' => 'end'],
            'is_featured' => 'Featured',
            'released_at' => 'Released',
        ];
    }
}

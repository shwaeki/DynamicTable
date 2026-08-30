<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * A per-column filter row. Each input is typed: text uses contains, numbers use
 * equality, dates use whereDate, enums and booleans match exactly.
 */
class ColumnSearchTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['column_search'];

    protected function columns(): array
    {
        return ['name', 'sku', 'category.name' => 'Category', 'status', 'price', 'stock'];
    }
}

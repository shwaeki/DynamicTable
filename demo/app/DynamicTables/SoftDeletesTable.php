<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Soft deletes are opt-in, because most tables do not want them.
 *
 * With the feature on, the table understands three modes — without trashed
 * (the default), with trashed, and only trashed — and trashed rows are marked
 * in the UI. One product is soft-deleted in the seed data.
 */
class SoftDeletesTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['soft_deletes', 'bulk-actions'];

    protected function columns(): array
    {
        return ['name', 'sku', 'category.name' => 'Category', 'price', 'status'];
    }
}

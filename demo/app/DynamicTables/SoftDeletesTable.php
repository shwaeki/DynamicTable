<?php

namespace App\DynamicTables;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Soft deletes need no feature: they are a question about the query.
 *
 * Eloquent already says all three things — the default scope hides trashed
 * rows, withTrashed() includes them, onlyTrashed() shows nothing else — so the
 * package would only be wrapping a builder method in a flag. Say it in query()
 * instead, where it reads as what it is.
 *
 * The one thing the package does contribute is recognising a trashed row and
 * striking it through, which happens whenever the model uses SoftDeletes. One
 * product is soft-deleted in the seed data.
 */
class SoftDeletesTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['bulk-actions'];

    /* Ascending, so the seeded soft-deleted row is on the first page. */
    protected array $defaultSort = ['id' => 'asc'];

    public function query(Builder $query): Builder
    {
        return $query->withTrashed();
    }

    protected function columns(): array
    {
        return ['id' => 'ID', 'name', 'sku', 'category.name' => 'Category', 'price', 'status'];
    }
}

<?php

namespace App\DynamicTables;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * "New" opens a blank row at the top of the table.
 *
 * Creating is editing without a record yet: the same controls, the same column
 * metadata, the same rules — and one request at the end rather than one per
 * cell, so a half-typed record never reaches the database.
 */
class InlineCreateTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = [Feature::CREATE];

    protected array $defaultSort = ['created_at' => 'desc'];

    protected function columns(): array
    {
        return [
            'name' => ['editable' => true],
            'sku' => ['editable' => true],
            'status' => ['editable' => true],
            'price' => ['editable' => true, 'align' => 'end'],
            'stock' => ['editable' => true, 'align' => 'end'],
            'created_at' => 'Created',
        ];
    }

    /** Columns the blank row does not ask for still need values. */
    public function newRecordDefaults(): array
    {
        return [
            'category_id' => Category::query()->value('id'),
            'status' => ProductStatus::Draft,
            'is_featured' => false,
            'stock' => 0,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'sku' => ['required', 'string', 'max:32', 'unique:products,sku'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['integer', 'min:0'],
        ];
    }
}

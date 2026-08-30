<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Select rows, then set the same values on all of them.
 *
 * Only the columns marked editable are offered, and only the fields ticked are
 * written — an untouched field is never sent, so a bulk edit of "status"
 * cannot silently blank anything else.
 *
 * The update runs record by record, chunked: each row is authorised on its own
 * and saved through the model, so policies apply and observers fire.
 */
class BulkEditTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = [Feature::BULK_EDIT];

    protected function columns(): array
    {
        return [
            'name' => ['editable' => true],
            'sku' => ['editable' => false],
            'status' => ['editable' => true],
            'price' => ['editable' => true, 'align' => 'end'],
            'stock' => ['editable' => true, 'align' => 'end'],
            'is_featured' => ['label' => 'Featured', 'editable' => true],
        ];
    }

    public function rules(): array
    {
        return [
            'price' => ['numeric', 'min:0'],
            'stock' => ['integer', 'min:0'],
        ];
    }
}

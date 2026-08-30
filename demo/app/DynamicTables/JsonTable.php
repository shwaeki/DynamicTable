<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * JSON columns are discovered but hidden by default, because a JSON blob makes
 * a poor column. Ask for it explicitly and it is rendered compactly and can be
 * filtered with contains / does not contain.
 */
class JsonTable extends DynamicTable
{
    protected string $model = Product::class;

    protected function columns(): array
    {
        return [
            'name',
            'sku',
            'attributes' => ['label' => 'Attributes', 'visible' => true, 'wrap' => true],
            'status',
        ];
    }
}

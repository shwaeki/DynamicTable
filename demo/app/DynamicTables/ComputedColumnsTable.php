<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * "Margin" is an accessor in $appends. The package displays and exports it, but
 * marks it computed: try sorting it — the header is not clickable, because the
 * value does not exist in SQL. The package enforces that rather than producing
 * a confusing query.
 */
class ComputedColumnsTable extends DynamicTable
{
    protected string $model = Product::class;

    protected function columns(): array
    {
        return [
            'name',
            'price' => ['format' => 'currency:USD', 'align' => 'end'],
            'margin' => ['label' => 'Margin (computed)', 'align' => 'end'],
            'stock',
            'status',
        ];
    }
}

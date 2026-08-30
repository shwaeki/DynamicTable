<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Formatting runs on the server, so it follows the application locale and the
 * same values land in exports. A render closure gets the value and the model;
 * anything it returns as HTML needs 'raw' => true and your own escaping.
 */
class FormattingTable extends DynamicTable
{
    protected string $model = Product::class;

    protected function columns(): array
    {
        return [
            'name',
            'price' => ['format' => 'currency:USD', 'align' => 'end'],
            'stock' => [
                'label' => 'Stock',
                'align' => 'end',
                'raw' => true,
                'render' => function (mixed $value, $product): string {
                    $level = $value > 100 ? 'ok' : ($value > 20 ? 'low' : 'critical');

                    return '<span class="stock stock-'.$level.'">'.e((string) $value).'</span>';
                },
            ],
            'released_at' => ['format' => 'since', 'label' => 'Released'],
            'is_featured' => 'Featured',
            'status',
        ];
    }
}

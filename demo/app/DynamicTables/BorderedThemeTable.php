<?php

namespace App\DynamicTables;

use App\Models\OrderItem;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The "bordered" theme: the same framework-free base, ruled like a spreadsheet.
 *
 * Dense rows and a border on every cell — the right default for wide numeric
 * grids, where following a value across twenty columns matters more than white
 * space.
 */
class BorderedThemeTable extends DynamicTable
{
    protected string $model = OrderItem::class;

    protected ?string $theme = 'bordered';

    protected function columns(): array
    {
        return [
            'order.reference' => 'Order',
            'product.name' => 'Product',
            'product.sku' => 'SKU',
            'quantity' => ['align' => 'end'],
            'unit_price' => ['format' => 'currency:USD', 'align' => 'end'],
            'created_at' => 'Added',
        ];
    }
}

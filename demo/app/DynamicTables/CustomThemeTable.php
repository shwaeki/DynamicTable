<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * A complete custom theme is one array, under the "themes" key of
 * config/dynamic-table.php — see this demo's copy of it. No service provider,
 * no Blade files, no CSS build.
 *
 * Keep the structural dynamic-table-* classes in your values: they carry
 * behaviour (sticky header, resize handles, dialog layout, RTL mirroring),
 * not looks.
 */
class CustomThemeTable extends DynamicTable
{
    protected string $model = Product::class;

    protected ?string $theme = 'demo';

    protected array $features = ['column_picker'];

    protected function columns(): array
    {
        return ['name', 'sku', 'category.name' => 'Category', 'price', 'stock', 'status'];
    }
}

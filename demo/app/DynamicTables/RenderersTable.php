<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The built-in cell renderers, which are formats rather than a new concept.
 *
 * Each of these is one word in the column definition. They render on the
 * server as inline SVG or a span with a class — no chart library, no client
 * work — and they keep their text inside the markup, so an export of this
 * table still reads as numbers rather than as HTML.
 */
class RenderersTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['export', 'print'];

    protected function columns(): array
    {
        return [
            // The value is a URL; the argument is the alt text.
            'image_url' => ['label' => '', 'format' => 'avatar:Product', 'sortable' => false, 'width' => 60],

            'name',

            // A bar out of 100 by default; pass the value that counts as full.
            'stock' => ['label' => 'Stock', 'format' => 'progress:500', 'summary' => 'sum'],

            // Stars, with the number kept for screen readers.
            'rating' => ['format' => 'rating', 'align' => 'center'],

            // An array of numbers becomes a trend line.
            'trend' => ['label' => 'Last 7 days', 'format' => 'sparkline', 'sortable' => false],

            // An array, or a comma-separated string, becomes pills.
            'tags' => ['format' => 'chips:3', 'sortable' => false],

            // Seconds as "1h 20m".
            'build_seconds' => ['label' => 'Build time', 'format' => 'duration'],

            'price' => ['format' => 'currency:USD', 'align' => 'end', 'summary' => 'avg'],
        ];
    }
}

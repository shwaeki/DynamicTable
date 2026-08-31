<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Badges and placeholders, declared rather than rendered.
 *
 * Every one of these columns used to need a render closure returning an
 * HtmlString. They are options now: the map says which tone a value gets, the
 * label stays inside the markup so an export still reads as words, and a
 * closure covers the rows where the colour depends on the record.
 */
class BadgesTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['export', 'print'];

    protected function columns(): array
    {
        return [
            'name',
            'sku' => 'SKU',

            // Keyed by the stored value; the label is the one the column
            // already shows, so an enum keeps its own wording.
            'status' => ['badges' => [
                'active' => 'success',
                'draft' => 'warning',
                'discontinued' => 'danger',
            ]],

            // A boolean, with the words it should read as.
            'is_featured' => ['label' => 'Featured', 'badges' => [1 => ['success', 'Featured'], 0 => ['neutral', 'Standard']]],

            // A tone on its own leaves the label alone — the price stays
            // formatted, and only the expensive ones are coloured.
            'price' => ['format' => 'currency:USD', 'align' => 'end', 'badges' => fn (mixed $value): ?string => $value >= 500 ? 'info' : null],

            // What a blank cell should say instead of a dash.
            'category.name' => ['label' => 'Category', 'empty' => 'Uncategorised'],
            'released_at' => ['label' => 'Released', 'empty' => 'Unreleased'],
        ];
    }
}

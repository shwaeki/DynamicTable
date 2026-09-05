<?php

namespace App\DynamicTables;

use App\Models\Category;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Drag a row by its grip; the position column is written on drop.
 *
 * A catalogue is the honest case for this: the order is a decision somebody
 * made, not a fact about the data, so it lives in a column and somebody has to
 * be able to change it.
 *
 * The handles are only there while the table is sorted by that column — under
 * any other sort, dropping a row between two others would describe a position
 * the table is not showing. Sort by Name from the header menu and the grips
 * disappear; sort back by Order and they return.
 */
class ReorderTable extends DynamicTable
{
    protected string $model = Category::class;

    protected array $features = [Feature::ROW_REORDER, Feature::HEADER_MENU, Feature::SORTING];

    /** The column a drag writes to. */
    protected ?string $reorderable = 'position';

    /** Sorted by it from the first paint, or the grips would start hidden. */
    protected array $defaultSort = ['position' => 'asc'];

    protected ?int $perPage = 25;

    protected function columns(): array
    {
        return [
            'position' => ['label' => 'Order', 'align' => 'end'],
            'name',
            'slug',
            'products_count' => ['label' => 'Products', 'align' => 'end'],
        ];
    }
}

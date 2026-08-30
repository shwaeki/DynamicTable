<?php

namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Choose, reorder (drag, or Alt + arrows) and resize columns.
 *
 * The chosen set is table state, so it is exactly what a saved view stores and
 * what an export produces.
 */
class ColumnPickerTable extends DynamicTable
{
    protected string $model = User::class;

    protected array $features = ['column_picker', 'column_reordering', 'column_resizing'];

    protected int $relationDepth = 2;
}

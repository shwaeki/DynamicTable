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

    protected array $features = ['column_picker', 'column_reorder', 'column_resize', 'remember_state'];

    /*
     * No responsive collapsing here.
     *
     * Collapsing hides the columns that do not fit, which is exactly what this
     * example is about arranging by hand — the two would be fighting over the
     * same thing in front of the reader.
     */
    protected ?string $responsive = 'none';

    protected int $relationDepth = 2;
}

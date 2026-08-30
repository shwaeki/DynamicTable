<?php

namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Column security.
 *
 * $allowedColumns is an exhaustive allowlist enforced in one place and
 * therefore everywhere: the column picker, the filter builder, sorting, search,
 * export and import all see the same four fields. Try adding a filter on
 * "salary" through the network tab — the server drops it.
 */
class HiddenColumnsTable extends DynamicTable
{
    protected string $model = User::class;

    protected array $features = ['column_picker'];

    protected array $allowedColumns = ['name', 'email', 'department.name', 'status'];
}

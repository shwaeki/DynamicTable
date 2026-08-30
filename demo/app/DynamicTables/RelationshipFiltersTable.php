<?php

namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Open "Filters" and pick a field from the Department or Role group.
 *
 * The value input is a searchable remote select backed by a paginated DISTINCT
 * query — the package never runs Department::all() to fill a dropdown.
 */
class RelationshipFiltersTable extends DynamicTable
{
    protected string $model = User::class;

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return ['name', 'email', 'department.name' => 'Department', 'role.name' => 'Role', 'status'];
    }
}

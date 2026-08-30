<?php

namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The whole point of the package: one property.
 *
 * Columns, types, formatting, the department name instead of department_id,
 * search, sorting, pagination and the filter builder all come from the model.
 */
class BasicUsersTable extends DynamicTable
{
    protected string $model = User::class;
}

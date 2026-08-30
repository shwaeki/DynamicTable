<?php

namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Two levels deep: user -> department -> company.
 *
 * $relationDepth also controls how far the filter builder and column picker
 * walk, so raising it exposes the company's fields there too.
 */
class NestedRelationshipsTable extends DynamicTable
{
    protected string $model = User::class;

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'name',
            'department.name' => 'Department',
            'department.company.name' => 'Company',
            'department.company.country' => 'Country',
            'role.name' => 'Role',
        ];
    }
}

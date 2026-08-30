<?php

namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Choosing columns explicitly. Four shapes are accepted and can be mixed:
 * a bare path, path => label, path => options, and path => renderer.
 */
class CustomColumnsTable extends DynamicTable
{
    protected string $model = User::class;

    protected function columns(): array
    {
        return [
            'name',
            'email',
            'department.name' => 'Team',
            'role.name' => 'Role',
            'salary' => ['format' => 'currency:USD', 'align' => 'end'],
            'joined_at' => ['format' => 'date:M Y', 'label' => 'Joined'],
        ];
    }
}

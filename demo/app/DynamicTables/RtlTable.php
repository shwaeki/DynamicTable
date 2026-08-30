<?php

namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * RTL, forced regardless of the app locale.
 *
 * The stylesheet is written with logical properties throughout, so the toolbar,
 * header alignment, sort indicators, resize handles, menus, the filter
 * builder's indentation and pagination all mirror — not just the text
 * direction. Use the language switcher above to see it with Arabic or Hebrew
 * translations.
 */
class RtlTable extends DynamicTable
{
    protected string $model = User::class;

    protected ?string $direction = 'rtl';

    protected array $features = ['column_picker', 'export'];

    protected function columns(): array
    {
        return ['name', 'email', 'department.name' => 'Department', 'status', 'salary', 'joined_at'];
    }
}

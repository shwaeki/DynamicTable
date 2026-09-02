<?php

namespace App\DynamicTables;

use App\Models\Customer;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The "tailwind" theme: the same table, rendered with Tailwind utilities.
 *
 * A Tailwind application is never served Bootstrap classes, and vice versa —
 * the theme is only a class map, read by the same Blade template and the same
 * JavaScript renderer as every other table on this site.
 */
class TailwindTable extends DynamicTable
{
    protected string $model = Customer::class;

    protected ?string $theme = 'tailwind';

    protected array $features = ['column_picker'];

    protected function columns(): array
    {
        return [
            'name',
            'email',
            'company.name' => 'Company',
            'country',
            'is_active' => 'Active',
            'lifetime_value' => ['format' => 'currency:USD', 'align' => 'end'],
        ];
    }
}

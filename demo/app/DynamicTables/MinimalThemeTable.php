<?php

namespace App\DynamicTables;

use App\Models\Customer;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The "minimal" theme: ready to use with no CSS framework at all.
 *
 * Every class it names is styled by the package's own stylesheet, on the same
 * tokens as everything else — so it is readable in light and dark, follows
 * data-dt-scheme, and needs neither Bootstrap nor Tailwind on the page.
 */
class MinimalThemeTable extends DynamicTable
{
    protected string $model = Customer::class;

    protected ?string $theme = 'minimal';

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

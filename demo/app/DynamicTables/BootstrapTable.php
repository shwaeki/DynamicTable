<?php

namespace App\DynamicTables;

use App\Models\Customer;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The same table, rendered with Bootstrap 5 classes.
 *
 * There is one Blade template and one JavaScript renderer; a theme is just a
 * class map. A Bootstrap application is never served Tailwind classes.
 */
class BootstrapTable extends DynamicTable
{
    protected string $model = Customer::class;

    protected ?string $theme = 'bootstrap';

    protected array $features = ['column_picker', 'export'];

    protected function columns(): array
    {
        return ['name', 'email', 'company.name' => 'Company', 'country', 'is_active', 'lifetime_value'];
    }
}

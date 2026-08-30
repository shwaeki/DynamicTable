<?php

namespace App\DynamicTables;

use App\Models\Customer;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Global search over a deliberately small set of columns — including one that
 * lives on a relationship, which compiles to whereHas rather than a join.
 */
class SearchTable extends DynamicTable
{
    protected string $model = Customer::class;

    protected array $searchable = ['name', 'email', 'company.name'];

    protected function columns(): array
    {
        return ['name', 'email', 'company.name' => 'Company', 'country', 'is_active'];
    }
}

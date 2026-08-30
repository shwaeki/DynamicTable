<?php

namespace App\DynamicTables;

use App\Models\Customer;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Saved views.
 *
 * Filter and reorder the table, then Views -> "Save as new view". Views store
 * declarative state — columns, order, widths, filters, sort, search — never
 * generated SQL, so they survive schema changes.
 *
 * Views -> "Manage views" is where a user chooses which view opens by default:
 * click a star to set it, click again to clear it. An administrator can star a
 * shared view, which becomes everyone's default until they pick their own.
 *
 * The demo signs you in as a fixed user and grants the system-views gate, so
 * "Share with everyone" is available.
 */
class SavedViewsTable extends DynamicTable
{
    protected string $model = Customer::class;

    protected array $features = ['views', 'column_picker', 'column_reordering'];

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'name',
            'email',
            'company.name' => 'Company',
            'country',
            'is_active' => 'Active',
            'lifetime_value' => ['format' => 'currency:USD', 'label' => 'Lifetime value'],
            'created_at',
        ];
    }

    public function presets(): array
    {
        return [
            'top_customers' => [
                'name' => 'Top customers',
                'sort' => [['field' => 'lifetime_value', 'direction' => 'desc']],
            ],
        ];
    }
}

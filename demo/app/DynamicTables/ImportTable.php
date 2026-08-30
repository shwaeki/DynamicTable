<?php

namespace App\DynamicTables;

use App\Models\Customer;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Import: upload, map, preview, run.
 *
 * Start with "Download template" — it carries the expected headings plus a hint
 * row showing formats and allowed values. Headings are matched to columns
 * automatically and you can correct any of them.
 *
 * A column mapped to "Company" is resolved to company_id by looking up the
 * name, with the lookups cached for the whole import. Rows that fail validation
 * are reported individually and collected into a downloadable error report; the
 * good rows still import.
 */
class ImportTable extends DynamicTable
{
    protected string $model = Customer::class;

    protected array $features = ['import', 'export'];

    protected function columns(): array
    {
        return [
            'name',
            'email',
            'company.name' => 'Company',
            'country',
            'phone',
            'is_active' => 'Active',
            'lifetime_value' => 'Lifetime value',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'country' => ['nullable', 'string', 'size:2'],
        ];
    }
}

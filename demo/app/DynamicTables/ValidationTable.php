<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Validation on inline edits.
 *
 * Try setting a price to 0, or above 5000, or clearing the name. The rule runs
 * in Laravel, the error comes back keyed by row and column, and it is shown on
 * the offending cell — the value is never written.
 *
 * Columns you do not declare rules for still get rules derived from the schema:
 * nullability, type, enum cases and string length.
 */
class ValidationTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['inline_edit'];

    protected function columns(): array
    {
        return [
            'name',
            'sku',
            'price' => ['align' => 'end'],
            'stock' => ['align' => 'end'],
            'status',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'sku' => ['required', 'string', 'regex:/^SKU-\d{5}$/'],
            'price' => ['required', 'numeric', 'between:1,5000'],
            'stock' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function validationMessages(): array
    {
        return [
            'price.between' => 'Price must be between 1 and 5000.',
            'sku.regex' => 'SKU must look like SKU-00123.',
        ];
    }
}

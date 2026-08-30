<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Spreadsheet mode.
 *
 * Click a cell, then: arrows move, Shift+arrows select a range, Ctrl/Cmd+C and
 * Ctrl/Cmd+V copy and paste rectangular ranges (paste from Excel works),
 * Ctrl/Cmd+D fills down, Ctrl/Cmd+Z undoes, Delete clears, Ctrl/Cmd+S saves.
 *
 * Nothing reaches the database until you save, and every pasted value is
 * validated by Laravel exactly like a single inline edit.
 */
class SpreadsheetTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['spreadsheet'];

    protected ?int $perPage = 25;

    protected function columns(): array
    {
        return [
            'name',
            'sku',
            'price' => ['align' => 'end'],
            'stock' => ['align' => 'end'],
            'status',
            'is_featured' => 'Featured',
        ];
    }

    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'between:1,5000'],
            'stock' => ['required', 'integer', 'min:0'],
        ];
    }
}

<?php

namespace App\DynamicTables;

use App\Enums\OrderStatus;
use App\Models\Order;
use Shwaeki\DynamicTable\Actions\BulkAction;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Every feature at once, so you can see what the ceiling looks like — and how
 * little configuration it still takes.
 */
class EverythingTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [
        'views',
        'export',
        'import',
        'bulk-actions',
        'inline_edit',
        'column_picker',
        'column_reordering',
        'column_resizing',
        'column_search',
        'soft_deletes',
        'url_state',
    ];

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'reference' => ['editable' => false],
            'customer.name' => 'Customer',
            'customer.country' => 'Country',
            'user.name' => 'Sales rep',
            'status',
            'total' => ['format' => 'currency:USD', 'align' => 'end'],
            'items_count' => 'Items',
            'placed_at' => 'Placed',
            'shipped_at' => 'Shipped',
        ];
    }

    public function rules(): array
    {
        return [
            'total' => ['required', 'numeric', 'min:0'],
            'items_count' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function actions(): array
    {
        return [
            BulkAction::update('mark_paid', ['status' => OrderStatus::Paid->value])->label('Mark as paid'),
            BulkAction::delete(),
        ];
    }

    public function presets(): array
    {
        return [
            'open' => [
                'name' => 'Open orders',
                'filters' => [
                    'logic' => 'or',
                    'conditions' => [
                        ['field' => 'status', 'operator' => 'equals', 'value' => 'pending'],
                        ['field' => 'status', 'operator' => 'equals', 'value' => 'paid'],
                    ],
                ],
            ],
        ];
    }
}

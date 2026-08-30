<?php

namespace App\DynamicTables;

use App\Enums\OrderStatus;
use App\Models\Order;
use Shwaeki\DynamicTable\Actions\BulkAction;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Select rows (shift-click for a range), then use Actions.
 *
 * "Select all matching" is stored as a mode, not a list of ids: the browser
 * never sends thousands of keys, and the server rebuilds the set from the same
 * filters that produced the page. Filter first, then select all, and only the
 * filtered rows are affected.
 */
class BulkActionsTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = ['bulk-actions'];

    protected function columns(): array
    {
        return ['reference', 'customer.name' => 'Customer', 'status', 'total', 'placed_at'];
    }

    public function actions(): array
    {
        return [
            BulkAction::make('mark_shipped')
                ->label('Mark as shipped')
                ->confirm('Mark the selected orders as shipped?')
                ->handle(fn ($query) => $query->update([
                    'status' => OrderStatus::Shipped->value,
                    'shipped_at' => now(),
                ])),

            BulkAction::make('change_status')
                ->label('Change status…')
                ->fields([
                    'status' => [
                        'label' => 'New status',
                        'options' => array_map(
                            fn (OrderStatus $case): array => ['value' => $case->value, 'label' => $case->label()],
                            OrderStatus::cases(),
                        ),
                        'rules' => ['required', 'string'],
                    ],
                ])
                ->handle(fn ($query, array $input) => $query->update(['status' => $input['status']])),

            BulkAction::delete(),
        ];
    }
}

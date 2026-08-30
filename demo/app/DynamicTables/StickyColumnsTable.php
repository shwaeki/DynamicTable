<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\Actions\RowAction;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Freeze the identifying columns. Scroll sideways: they stay.
 *
 * CSS does the sticking; the package measures. Widths change with the data,
 * the column picker, resizing and the viewport, so the offsets are computed
 * rather than declared — and written as logical insets, which means the same
 * columns freeze on the correct edge in RTL.
 */
class StickyColumnsTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [Feature::STICKY_COLUMNS, Feature::ROW_ACTIONS];

    /** Frozen in the order given, starting from the leading edge. */
    protected array $stickyColumns = ['reference', 'customer__name'];

    /** The action buttons freeze against the opposite edge. */
    protected bool $stickyActions = true;

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'customer.email' => 'Email',
            'customer.country' => 'Country',
            'user.name' => 'Owner',
            'status',
            'total' => ['format' => 'currency:USD', 'align' => 'end'],
            'placed_at' => 'Placed',
            'shipped_at' => 'Shipped',
            'created_at' => 'Created',
            'updated_at' => 'Updated',
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('open')
                ->label('Open')
                ->icon('↗')
                ->url(fn (Order $order): string => 'https://example.com/orders/'.$order->reference, '_blank'),
        ];
    }
}

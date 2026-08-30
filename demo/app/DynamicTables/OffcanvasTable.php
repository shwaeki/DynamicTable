<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The same panels, docked to the side instead of centred.
 *
 * Open Filters or Columns and compare with any other example. Nothing about the
 * panels themselves changed: modal and offcanvas share the same markup, focus
 * trap and Escape handling, so this is a presentation choice you can flip at any
 * time — application-wide in the config, or per table like this.
 */
class OffcanvasTable extends DynamicTable
{
    protected string $model = Order::class;

    protected ?string $panels = 'offcanvas';

    protected array $features = ['views', 'export', 'column_picker', 'column_reordering'];

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'reference' => 'Order',
            'customer.name' => 'Customer',
            'status',
            'total' => ['format' => 'currency:USD', 'align' => 'end'],
            'placed_at' => 'Placed',
        ];
    }
}

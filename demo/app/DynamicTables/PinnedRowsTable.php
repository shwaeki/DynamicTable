<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Star a row and it stays at the top — for you, and for nobody else.
 *
 * Pin an order on page 4 and it is at the top of page 1, once. It is one
 * ORDER BY CASE rather than a second query or a union, so there is still one
 * result, one count and one page, and a pinned row is not also still where it
 * was.
 *
 * The list lives in the session, like remembered state and for the same reason:
 * a pin is a working note — "these three while I deal with them" — rather than
 * a document. Saved views are the durable, nameable, shareable version.
 */
class PinnedRowsTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [Feature::PINNED_ROWS, Feature::HEADER_MENU, Feature::SORTING];

    protected array $defaultSort = ['placed_at' => 'desc'];

    protected ?int $perPage = 10;

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'status',
            'total' => ['format' => 'currency:USD', 'align' => 'end'],
            'placed_at' => 'Placed',
        ];
    }
}

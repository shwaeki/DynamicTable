<?php

namespace App\DynamicTables;

use App\Models\Order;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Grouping, with each group carrying its own totals.
 *
 * Open the header menu on Status and group by it. Every heading gains the
 * subtotals for its own group, and the row under the table keeps the total for
 * the whole filtered result.
 *
 * The number on a heading is the *whole group's*, not the visible slice's: a
 * group cut in half by a page break still reports what the group holds, because
 * that is what the heading claims to describe. It costs one aggregate query,
 * bounded to the groups on the page.
 */
class GroupSummariesTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [Feature::GROUPING, Feature::HEADER_MENU, Feature::COLUMN_PICKER];

    /** Grouping is expressed as a leading sort, so the database does the work. */
    protected array $defaultSort = ['status' => 'asc'];

    protected ?int $perPage = 25;

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'status',

            // Each of these appears twice once the table is grouped: on every
            // heading, and once under the table.
            'total' => ['format' => 'currency:USD', 'align' => 'end', 'summary' => 'sum'],
            'placed_at' => ['label' => 'Placed', 'summary' => 'max'],
        ];
    }
}

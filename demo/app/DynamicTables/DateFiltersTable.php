<?php

namespace App\DynamicTables;

use App\Models\Invoice;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Date operators include relative ranges — today, this month, last year,
 * "in the last N days" — resolved on the server against the app timezone.
 */
class DateFiltersTable extends DynamicTable
{
    protected string $model = Invoice::class;

    protected array $defaultSort = ['due_on' => 'desc'];

    protected function columns(): array
    {
        return [
            'number',
            'order.reference' => 'Order',
            'status',
            'amount' => ['format' => 'currency:USD'],
            'due_on' => 'Due',
            'paid_at' => 'Paid',
        ];
    }
}

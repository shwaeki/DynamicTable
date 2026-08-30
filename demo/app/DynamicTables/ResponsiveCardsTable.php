<?php

namespace App\DynamicTables;

use App\Models\Customer;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Responsive "cards" mode.
 *
 * Below the configured breakpoint each row stacks into a labelled block. Better
 * than collapsing when every field matters and there is no obvious hierarchy —
 * a contact list, for instance.
 *
 * The third option is 'scroll', which keeps the grid intact and scrolls
 * horizontally; it needs no JavaScript at all.
 */
class ResponsiveCardsTable extends DynamicTable
{
    protected string $model = Customer::class;

    protected ?string $responsive = 'cards';

    protected function columns(): array
    {
        return [
            'name',
            'email',
            'phone',
            'company.name' => 'Company',
            'country',
            'lifetime_value' => ['format' => 'currency:USD', 'label' => 'Lifetime value'],
        ];
    }
}

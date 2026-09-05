<?php

namespace App\DynamicTables;

use App\Models\Order;
use Illuminate\Support\HtmlString;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Your own markup, in the five places the template leaves for it.
 *
 * Before this existed, adding a control to the toolbar meant copying
 * table.blade.php — which works once and turns every upgrade into a merge. A
 * slot is the supported way to do the same thing and stay on the template.
 *
 * Each name is somewhere the package promises not to draw anything itself, so
 * an application's markup and the table's own can never fight over one box.
 */
class SlotsTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $features = [Feature::HEADER_MENU, Feature::SORTING];

    protected array $defaultSort = ['placed_at' => 'desc'];

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

    /**
     * Values take the same three shapes as rowDetail(): a Blade view, an
     * Htmlable, or a string — which is escaped, because a bare string is text.
     * An unknown slot name throws, for the same reason an unknown feature name
     * does.
     */
    public function slots(): array
    {
        return [
            // Beside the search box. display: contents means it joins the
            // toolbar's own flex row rather than sitting in a nested box.
            'toolbar.start' => view('partials.slot-toolbar'),

            // A band between the toolbar and the table.
            'above' => new HtmlString(
                '<p class="dynamic-table-slot-note">Orders are synced from the warehouse every morning at 06:00.</p>'
            ),

            // Under the pagination footer.
            'below' => 'Escaped, because a bare string is text — <not markup>.',

            // Only when the table is genuinely empty, never when a filter
            // matched nothing: "add the first order" answers a question the
            // reader did not ask.
            'empty' => view('partials.slot-empty'),
        ];
    }
}

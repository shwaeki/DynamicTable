<?php

namespace App\DynamicTables;

use App\Models\Order;
use App\Support\BuilderOptions;
use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\Actions\BulkAction;
use Shwaeki\DynamicTable\Actions\RowAction;
use Shwaeki\DynamicTable\Actions\ToolbarAction;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * The table behind the builder page.
 *
 * Everything a table normally declares as properties is taken from the
 * builder's current selection instead, so switching a checkbox produces the
 * same table the generated code would — this is the real package doing the real
 * thing, not a mock-up of it.
 *
 * The selection lives in the session rather than in the URL because the data
 * endpoint is a separate request: the browser asks for rows by table key, and
 * the table has to configure itself the same way then as it did when the page
 * was rendered.
 */
class BuilderTable extends DynamicTable
{
    protected string $model = Order::class;

    protected array $defaultSort = ['placed_at' => 'desc'];

    protected int $relationDepth = 2;

    public function __construct()
    {
        $options = BuilderOptions::current();

        // Not the raw ticks: a bare list only *adds* to the defaults, so
        // unticking "search" has to be declared as '-search' or the preview
        // would keep showing a search box nobody asked for.
        $this->features = BuilderOptions::declaration($options['features']);
        $this->theme = $options['theme'];
        $this->panels = $options['panels'];
        $this->responsive = $options['responsive'];
        $this->pagination = $options['pagination'];
        $this->perPage = (int) $options['perPage'];
        $this->maxHeight = $options['maxHeight'];
        $this->direction = $options['direction'] === 'auto' ? null : $options['direction'];
        $this->scheme = $options['scheme'] === 'auto' ? null : $options['scheme'];
        $this->stickyColumns = $options['sticky'] ? ['reference'] : [];
        $this->stickyActions = (bool) $options['sticky'];
        $this->facets = ['status'];
        $this->summarise = (bool) $options['summary'];
        $this->linked = (bool) $options['links'];
    }

    /** Whether the money column carries a total, driven by the builder. */
    private bool $summarise = true;

    /** Whether rows are links, driven by the builder. */
    private bool $linked = false;

    protected function columns(): array
    {
        return [
            'reference',
            'customer.name' => 'Customer',
            'customer.country' => 'Country',
            'status' => ['editable' => true],
            'total' => ['format' => 'currency:USD', 'align' => 'end', 'editable' => true, 'summary' => $this->summarise ? 'sum' : null],
            'placed_at' => 'Placed',
            'shipped_at' => 'Shipped',
        ];
    }

    /*
     * The hooks below are declared unconditionally.
     *
     * A hook without its feature renders nothing, which is exactly the point
     * the builder is making: features are what decide, not the presence of
     * code.
     */

    public function rowActions(): array
    {
        return [
            RowAction::make('open')
                ->label('Open')
                ->icon('↗')
                ->url(fn (Order $order): string => 'https://example.com/orders/'.$order->reference, '_blank'),
        ];
    }

    public function toolbar(): array
    {
        return [
            ToolbarAction::make('sync')
                ->label('Sync')
                ->icon('↻')
                ->handle(fn (): string => 'Synced.'),
        ];
    }

    public function actions(): array
    {
        return [
            BulkAction::make('flag')
                ->label('Flag as urgent')
                ->handle(fn ($query): int => $query->count()),
        ];
    }

    public function rowDetail(Model $record): mixed
    {
        return view('partials.order-detail', ['order' => $record->load('items.product', 'customer')]);
    }

    /**
     * Unlike the hooks above, this one *is* conditional.
     *
     * There is no feature to switch it off with: a rowUrl() that returns a URL
     * makes every row a link, so the tick has to reach the method itself.
     */
    public function rowUrl(Model $record): ?string
    {
        return $this->linked ? route('examples.show', 'row-links').'#'.$record->reference : null;
    }

    public function rules(): array
    {
        return ['total' => ['numeric', 'min:0']];
    }
}

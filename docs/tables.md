# The table class

The complete public API of a DynamicTable, in one page. Everything except
`$model` is optional.

```php
namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

class UsersTable extends DynamicTable
{
    protected string $model = User::class;
}
```

## Properties

| Property | Default | Purpose |
|---|---|---|
| `$model` | — | **Required.** The Eloquent model. |
| `$tableKey` | derived | Stable identifier used by saved views and the data endpoint. `UsersTable` → `users`. |
| `$title` | derived | Heading / accessible label. |
| `$features` | see below | Opt-in features; `-name` switches a default off. |
| `$columns` | `[]` | Column configuration (same shape as `columns()`). |
| `$searchable` | auto | Paths the global search box queries. |
| `$hiddenColumns` | `[]` | Paths never exposed anywhere. |
| `$allowedColumns` | `[]` | Exhaustive allowlist when non-empty. |
| `$labels` | `[]` | Label overrides keyed by path. |
| `$defaultSort` | `created_at desc` | e.g. `['name' => 'asc']`. |
| `$scopes` | `[]` | Eloquent scopes always applied. |
| `$with` | `[]` | Extra relations to eager load. |
| `$pagination` | `'auto'` | `'length_aware'`, `'simple'`, `'auto'` or `'infinite'`. See [Performance](performance.md#counting-is-the-thing-that-stops-scaling). |
| `$perPage` (`?int`) | config (25) | Keep the `?` — it must match the parent declaration. |
| `$perPageOptions` | config | |
| `$theme` | config | |
| `$maxHeight` (`?string`) | config (`70vh`) | Height of the scroll area. What makes the sticky header stick; `'none'` for page-flow height. |
| `$printView` (`?string`) | config | A print template for this table only. |
| `$printStylesheets` | `[]` | Stylesheets the print page loads before its own. |
| `$direction` | auto | `'ltr'`, `'rtl'`, or null to follow the locale. |
| `$scheme` (`?string`) | auto | `'light'`, `'dark'`, or null to follow the viewer's system. |
| `$responsive` | `'collapse'` | `'collapse'`, `'scroll'`, `'cards'` or `'none'`. See [Responsive](responsive.md). |
| `$responsiveFixed` | first column | Column paths that never collapse. |
| `$stickyColumns` | `[]` | Column keys frozen from the leading edge. Needs `sticky_columns`. |
| `$stickyActions` | `false` | Freeze the row action buttons against the opposite edge. |
| `$filterCounts` | `[]` | Columns whose filter values carry counts. Needs `filter_counts`. |
| `$relationDepth` | 1 | How deep the filter builder and column picker walk relations. |
| `$params` | `[]` | External parameters `query()` may read. See [Parameters](#parameters). |
| `$paramFilters` | `[]` | Parameters bound straight to the query. See [Filters from parameters](#filters-from-parameters). |
| `$policy` | null | Ability prefix when you use gates rather than a policy class. |

## Methods

```php
protected function columns(): array;                    // see docs/columns.md
public function query(Builder $query): Builder;         // the base query
public function paramFilters(): array;                  // parameters bound to the query
public function actions(): array;                       // bulk actions
public function rowActions(): array;                    // per-row buttons
public function toolbar(): array;                       // your own toolbar buttons
public function rowDetail(Model $record): mixed;        // the expanded row panel
public function rowUrl(Model $record): ?string;         // where a row click goes
public function slots(): array;                         // your markup, in the template's five slots
public function newRecordDefaults(): array;             // values for the create row
public function rules(): array;                         // validation, keyed by path
public function validationMessages(): array;
public function presets(): array;                       // developer-defined views
public function authorize(string $ability, ?Model $record = null): ?bool;
```

## Clickable rows

```php
public function rowUrl(Model $record)
{
    return route('orders.show', $record);
}
```

The first cell of every row becomes a **real link** to it. That word is doing
work: a link is focusable, is announced as a link, opens in a new tab on a
middle-click, and offers "copy link address". A click handler does none of
those, which is why the handler is only the convenience on top — it makes the
rest of the row follow the same URL, and modified clicks open a tab the way a
link would.

```php
protected string $rowClick = 'single';   // 'single' | 'double' | 'none'
```

The default is `'double'` on a table with inline editing and `'single'`
everywhere else, because double-click already opens the editor there and a
single click that navigates away from a cell someone was about to edit is the
more annoying collision. `'none'` keeps the link in the first cell and leaves
the rest of the row alone.

A click never navigates from an editable cell, from anything already
interactive (a link, a button, an input, a label, a row action), or when it
ended a text selection.

## Parameters

`query()` is where a table stops being generic. Anything you can express in
Eloquent belongs there — a tenant scope, a soft business rule, a join:

```php
public function query(Builder $query): Builder
{
    return $query->with('items')->where('status', '!=', 'pending_to_pay');
}
```

What it cannot do on its own is *change with the page*, because the table's
rows are fetched over AJAX: `request('from_date')` is empty on that request,
whatever the address bar says. Declared parameters are the missing half —
your own controls, above the table or anywhere on the page, feeding values
into `query()` on every refresh.

Declare the names you accept — bare, or with a default:

```php
protected array $params = ['from_date', 'to_date', 'order_method', 'status' => 'open'];
```

Read them inside `query()`:

```php
public function query(Builder $query): Builder
{
    $query = $query->with('items', 'installments');

    if ($zreport = $this->param('zreport_id')) {
        $query->where('zreport_id', $zreport);
    } elseif ($this->param('from_date') || $this->param('to_date')) {
        $from = Carbon::parse($this->param('from_date', now()->toDateString()).' '.$this->param('from_time', '00:00'));
        $to = Carbon::parse($this->param('to_date', now()->toDateString()).' '.$this->param('to_time', '23:59'));

        $query->whereBetween('created_at', [$from->startOfMinute(), $to->endOfMinute()]);
    } else {
        $query->currentShift();
    }

    foreach (['order_method', 'payment_method', 'status', 'source'] as $name) {
        $query->when($this->param($name), fn ($query, $value) => $query->where($name, $value));
    }

    if ($product = $this->param('product_id')) {
        $query->whereHas('items', fn ($items) => $items->where('item_id', $product));
    }

    return $query->byCashierType($this->param('cashier_type'))->latest();
}
```

`param($name, $default = null)` returns the declared default when nothing was
sent, and your `$default` when the value is empty. `params()` returns them all,
and `hasParam()` answers whether one arrived.

### Filters from parameters

Most of that `query()` body is one line repeated: take a parameter, put it in a
`where`. `$paramFilters` says the same thing declaratively, and the whole block
above collapses to:

```php
protected array $paramFilters = [
    'status',                                                 // where('status', $value)
    'category' => 'company_category_id',                      // the parameter is not the column
    'q' => ['column' => 'name', 'operator' => 'contains'],
    'area' => ['column' => 'companyArea.slug'],               // through a relation
    'created_period' => ['column' => 'created_at', 'operator' => 'period'],
];
```

Every name here is a declared parameter, so `$params` does not repeat them, and
a filter whose parameter did not arrive is not applied. A value still only ever
reaches SQL as a bound value — the column comes from your class.

| `operator` | Does |
|---|---|
| `equals` (default) | `where($column, $value)`, or `whereIn` when the value is a list |
| `not_equals`, `>`, `>=`, `<`, `<=` | The comparison, named or symbolic (`greater_or_equal` = `>=`) |
| `contains`, `starts_with`, `ends_with` | `LIKE`, with the value's own wildcards escaped |
| `in`, `not_in` | Against a list |
| `between` | A two-value list |
| `date` | `whereDate`, for a day rather than an instant |
| `period` | A date window — see below |

A dotted column is a relation path, and becomes a `whereHas`, exactly as it
would in the filter builder.

**Periods.** One parameter drives the whole date picker:

```php
'created_period' => ['column' => 'created_at', 'operator' => 'period'],
```

Its value is either a window reaching back from now — `day`, `week`, `month`,
`quarter`, `year` — or any relative operator the filter builder knows (`today`,
`yesterday`, `this_week`, `last_month`, `this_year`, `last_year`,
`last_n_days:30`), or `custom`, which reads the two companion parameters. Those
are `created_from` and `created_to` here, because a picker named
`<thing>_period` pairs with `<thing>_from` and `<thing>_to`; name them yourself
with `'from' => …, 'to' => …` if they are called something else. They are
declared for you either way, and they also work with no picker at all.

**Anything else.** A filter can be a closure — but PHP does not allow one in a
property default, so it comes from the method:

```php
public function paramFilters(): array
{
    return [
        ...parent::paramFilters(),
        'agent' => fn (Builder $query, $value) => $query->whereHas('agent', fn ($q) => $q->where('code', $value)),
    ];
}
```

`query()` still runs, before the declared filters, for everything that is not a
parameter: the tenant scope, the eager loads, the business rules.

### Where the values come from

1. **The page request, on first paint.** `/orders?status=paid` reaches a table
   that declares `status` with no wiring at all — the Yajra-style
   `request('status')` case. Prefix the name with the table key
   (`?orders_status=paid`) when two tables on one page would collide.
2. **`@dynamicTable`**, when the controller already knows the values:

   ```blade
   @dynamicTable(OrdersTable::class, ['params' => ['zreport_id' => $report->id]])
   ```

3. **Your own controls**, on every refresh afterwards.

Only declared names are accepted, and values must be a scalar or a flat list of
them — an attacker cannot smuggle a new parameter, an array of operators, or a
1 MB string through this endpoint. Parameters travel with the state, so an
export, a print or a bulk action over "everything that matches" sees exactly
the rows on screen.

### Wiring your controls

Two attributes, and nothing else:

| Attribute | On | Says |
|---|---|---|
| `data-dynamic-table-param="<name>"` | the control | which parameter it sets |
| `data-dynamic-table-table="<key>"` | the control | which table it sets it on |
| `data-dynamic-table-params="<key>"` | a wrapper | the key for every control inside it |
| `data-dynamic-table-params-reset="<key>"` | a button | clear them all and reload once |

The key is the table's own — `$tableKey` when the class sets one, otherwise the
one derived from the class name. A table whose filters live outside it is worth
naming, so the Blade is not coupled to a class name:

```php
class OrdersTable extends DynamicTable
{
    protected ?string $tableKey = 'orders';

    protected array $paramFilters = [
        'status',                                                  // where('status', $value)
        'country' => ['column' => 'customer.country'],             // through a relation
        'min_total' => ['column' => 'total', 'operator' => '>='],
        'placed_period' => ['column' => 'placed_at', 'operator' => 'period'],
    ];

    /** Runs first, and owns what the table may show at all. */
    public function query(Builder $query): Builder
    {
        return $query->where('agent_id', Auth::id());
    }
}
```

The markup is your application's, in your application's classes — this is
Bootstrap, but nothing here is package markup:

```blade
<div class="row g-2" data-dynamic-table-params="orders">
    <select class="form-select" data-dynamic-table-param="status">
        <option value="">Any status</option>
        <option value="paid">Paid</option>
    </select>

    {{-- Spelled out in full, so it works outside the wrapper too. --}}
    <select class="form-select" data-dynamic-table-param="country" data-dynamic-table-table="orders">
        <option value="">Any country</option>
        <option value="US">United States</option>
    </select>

    <input type="number" class="form-control" data-dynamic-table-param="min_total">

    <select class="form-select" data-dynamic-table-param="placed_period">
        <option value="">Any time</option>
        <option value="month">Last month</option>
        <option value="year">Last year</option>
    </select>

    <button type="button" class="btn btn-outline-secondary" data-dynamic-table-params-reset="orders">Reset</button>
</div>

@dynamicTable(OrdersTable::class)
```

Controls inside a `data-dynamic-table-params` wrapper may drop their own
`data-dynamic-table-table`; both spellings are shown above, and they mix freely.
Neither the parameter names nor the column names come from the browser — a
control naming a parameter the class did not declare is ignored.

The `param-filters` example in `demo/` is this, running.

Selects and dates apply on change, text inputs are debounced, and a form
submit applies the lot in one request. Every change resets to page 1 — an
answer on page 7 of a different question is not an answer.

Or drive it yourself:

```js
const table = window.DynamicTable.find('orders');

table.setParams({ from_date: '2026-01-01', to_date: '2026-01-31' });
table.setParam('status', 'paid');
table.getParams();
table.resetParams();

table.on('params-changed', (params) => console.log(params));
```

## Features

Enabled by default (cheap):

```
search   filters   sorting   pagination   responsive   header_menu
```

Opt-in (each costs a query, a JS module, or extra state):

```
saved_views        column_picker      column_reorder      column_resize
column_search      selection          bulk_actions        bulk_edit
row_actions        toolbar_actions    inline_edit         create
row_detail         sticky_columns     facets              grouping
export             import             print               relations
url_state          remember_state
```

Every one of them is documented — what it does, what it costs, what it implies
and which module it loads — in [All features](features.md).

```php
protected array $features = [
    'saved_views',
    'export',
    'bulk-actions',   // hyphens, camelCase and snake_case all work
    '-search',        // switch a default off
];
```

Some features imply others, so you never configure the same idea twice:

| Declared | Also enabled |
|---|---|
| `bulk_actions` | `selection` |
| `bulk_edit` | `selection` |
| `inline_create` | `inline_edit` |
| `saved_views` | `column_picker` |

`'only'` as the first entry starts from nothing:

```php
protected array $features = ['only', 'pagination'];   // a static list, no JS panels
```

A disabled feature renders no UI, registers no state, loads no JavaScript,
executes no queries and rejects its endpoint with an error — the guarantee is
enforced, not documentary.

## Rendering

```blade
@dynamicTable(UsersTable::class)
```

Options can be passed for one-off overrides:

```blade
@dynamicTable(UsersTable::class, ['theme' => 'bootstrap', 'perPage' => 50])
```

The same table can be rendered more than once per page; each instance gets its
own DOM id and state, and the shared assets are emitted only once.

## The table key

Used by saved views and by the data endpoint. It is derived deterministically
from the class name (`OrdersTable` → `orders`), and duplicates raise an
exception at registration time rather than silently colliding — set
`$tableKey` on one of them.

## Registration

Classes in `app/DynamicTables` are discovered automatically and the result is
cached. A table rendered through the directive is registered on first use, so
the directive alone is enough; explicit registration is only needed when your
tables live elsewhere:

```php
'tables' => [
    'paths' => [app_path('DynamicTables'), app_path('Domain/Reporting/Tables')],
    'register' => ['legacy_users' => App\Legacy\UsersGrid::class],
],
```

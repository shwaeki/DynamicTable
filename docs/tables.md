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
| `$direction` | auto | `'ltr'`, `'rtl'`, or null to follow the locale. |
| `$scheme` (`?string`) | auto | `'light'`, `'dark'`, or null to follow the viewer's system. |
| `$responsive` | `'collapse'` | `'collapse'`, `'scroll'`, `'cards'` or `'none'`. See [Responsive](responsive.md). |
| `$responsiveFixed` | first column | Column paths that never collapse. |
| `$stickyColumns` | `[]` | Column keys frozen from the leading edge. Needs `sticky_columns`. |
| `$stickyActions` | `false` | Freeze the row action buttons against the opposite edge. |
| `$facets` | `[]` | Columns whose filter values carry counts. Needs `facets`. |
| `$relationDepth` | 1 | How deep the filter builder and column picker walk relations. |
| `$policy` | null | Ability prefix when you use gates rather than a policy class. |

## Methods

```php
protected function columns(): array;                    // see docs/columns.md
public function query(Builder $query): Builder;         // the base query
public function actions(): array;                       // bulk actions
public function rowActions(): array;                    // per-row buttons
public function toolbar(): array;                       // your own toolbar buttons
public function rowDetail(Model $record): mixed;        // the expanded row panel
public function newRecordDefaults(): array;             // values for the create row
public function rules(): array;                         // validation, keyed by path
public function validationMessages(): array;
public function presets(): array;                       // developer-defined views
public function authorize(string $ability, ?Model $record = null): ?bool;
```

## Features

Enabled by default (cheap):

```
search   filters   sorting   pagination   responsive   header_menu
```

Opt-in (each costs a query, a JS module, or extra state):

```
views              column_picker      column_reordering   column_resizing
column_search      selection          bulk_actions        bulk_edit
row_actions        toolbar_actions    inline_edit         create
row_detail         sticky_columns     facets              grouping
export             import             spreadsheet         soft_deletes
url_state
```

Every one of them is documented — what it does, what it costs, what it implies
and which module it loads — in [All features](features.md).

```php
protected array $features = [
    'views',
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
| `create` | `inline_edit` |
| `spreadsheet` | `inline_edit`, `selection` |
| `views` | `column_picker` |
| `column_reordering` / `column_resizing` | `column_picker` |

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

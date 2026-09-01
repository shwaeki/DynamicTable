# Upgrade guide

## Versioning promise

Laravel DynamicTable follows semantic versioning against its **public API**,
which is deliberately small:

- the `DynamicTable` base class and its documented properties and methods
- `BulkAction`, `Theme`, the events, `DynamicTableView`
- the Blade directives and the config file
- the `data-dynamic-table-*` DOM hooks and the `dynamic-table:*` DOM events

Anything under `Metadata`, `Query`, `Filters`, `Columns`, `Support` or
`Http\Controllers`, and the JSON endpoint shape, is **internal** and may change
in a minor release. See [docs/extending.md](docs/extending.md).

## 2.0.0: `data-dt-*` attributes are now `data-dynamic-table-*`

The prefix rename covers the DOM hooks, which this guide names as public API.
Any Blade of your own that wires filter controls to a table stops working until
it is updated — the controls are simply not found, so the bar goes quiet rather
than erroring:

| Was | Now |
| --- | --- |
| `data-dt-param="status"` | `data-dynamic-table-param="status"` |
| `data-dt-table="orders"` | `data-dynamic-table-table="orders"` |
| `data-dt-params="orders"` | `data-dynamic-table-params="orders"` |
| `data-dt-params-reset="orders"` | `data-dynamic-table-params-reset="orders"` |

One pass over your views covers it:

```bash
grep -rl -- "data-dt-" resources/views/ \
  | xargs sed -i "s/data-dt-/data-dynamic-table-/g"
```

The same applies to any CSS of your own that overrode `.dt-*` classes or read
`--dt-*` custom properties, and to any JavaScript selecting on those hooks.
See [docs/tables.md](docs/tables.md#wiring-your-controls) for the current
markup, and the `param-filters` example in `demo/` for it running.

## 2.0.0: exports now default to XLSX

Where a spreadsheet library is installed, the export dialog, the import
template and the format each offers first are XLSX rather than CSV, and an
exported XLSX is a real Excel table.

Nothing breaks — CSV output is byte-for-byte what it was, and a file exported
either way still imports back. But an application that hands the download to
another program, or whose users expect a `.csv`, should say so:

```php
// config/dynamic-table.php
'excel' => [
    'default_format' => 'csv',   // the dialogs open on CSV again
    'style' => false,            // and an XLSX is a bare grid, not a table
],
```

`style` also takes an Excel built-in style name — `'TableStyleMedium2'` is the
default, and `TableStyleLight1`–`21`, `TableStyleMedium1`–`28` and
`TableStyleDark1`–`11` are the rest. **An unrecognised name throws**, so a typo
fails on the next export rather than quietly producing an unstyled file.

## 2.0.0: five features renamed
No aliases — and an unknown feature name now throws instead of being ignored,
so a name you miss fails loudly on the next render rather than quietly turning
something off.

| Was | Now |
| --- | --- |
| `column_reordering` | `column_reorder` |
| `column_resizing` | `column_resize` |
| `facets` | `filter_counts` |
| `views` | `saved_views` |
| `create` | `inline_create` |

The constants moved with them — `Feature::COLUMN_REORDERING` is
`Feature::COLUMN_REORDER`, `Feature::VIEWS` is `Feature::SAVED_VIEWS`, and so
on — as did the property behind `filter_counts`:

```php
// was
protected array $facets = ['status'];

// now
protected array $filterCounts = ['status'];
```

A find-and-replace over your own tables covers it:

```bash
grep -rl -- "column_reordering\|column_resizing\|'facets'\|'views'\|'create'" app/ \
  | xargs sed -i \
      -e "s/'column_reordering'/'column_reorder'/g" \
      -e "s/'column_resizing'/'column_resize'/g"
```

Be careful with the last three by hand: `'views'` and `'create'` are also route
names, gate abilities and config keys, and only the ones inside a `$features`
array are features. If you miss one, the exception will tell you which table.

Nothing else moved: the JavaScript module names, the `dynamic-table.views.*`
routes, the `create` ability and the `dynamic-table::table.views.*` translation
keys are unchanged.
## 2.0.0: `soft_deletes` is gone

The feature is removed. If a table declared it, drop it from `$features` — an
unknown name is ignored, so nothing breaks either way, but the behaviour it
gave you has moved.

It only ever did two things, and Eloquent does the first one better:

```php
use Illuminate\Database\Eloquent\Builder;

// was: protected array $features = ['soft_deletes'];
public function query(Builder $query): Builder
{
    return $query->withTrashed();     // or ->onlyTrashed()
}
```

The second — striking a trashed row through — is now automatic on any model
using `SoftDeletes`, so you get it whether or not you ask.

What genuinely goes away is the `trashed` value in table state: it is no longer
read from a request, stored in a saved view, remembered between visits, or
mirrored into the URL by `url_state`. **A saved view that carried one still
loads** — the unknown key is dropped the way any stale key is, and the view
keeps its columns, filters and sort. If a view existed only to say "show me the
deleted ones", replace it with a parameter:

```php
protected array $params = ['show' => 'live'];

public function query(Builder $query): Builder
{
    return $this->param('show') === 'trashed' ? $query->onlyTrashed() : $query;
}
```
## 2.0.0: the CSS prefix is now `dynamic-table`

`dt-` was too common a name to claim. Every class, custom property and data
attribute the package emits has been renamed; nothing else has.

**You are affected only if you wrote CSS, JS or tests against those names.** If
you use a built-in theme and never touched the stylesheet, upgrade and stop
reading.

| Before | After |
| --- | --- |
| `.dt` | `.dynamic-table` |
| `.dt-anything` | `.dynamic-table-anything` |
| `--dt-anything` | `--dynamic-table-anything` |
| `data-dt-anything` | `data-dynamic-table-anything` |

There are no aliases and no deprecation period: shipping both names would mean
every rule in the stylesheet twice, and a half-renamed page is harder to debug
than a renamed one.

Because the rule has no exceptions, the migration is one substitution over your
own files — your stylesheets, your theme arrays in `config/dynamic-table.php`,
any published views, and any browser test that queries a `data-dt-*` hook:

```bash
# GNU sed; on macOS use `sed -i ''`
grep -rl -- '-\?dt-' resources/ tests/ config/ \
  | xargs sed -i -E 's/(^|[^A-Za-z0-9_])dt-/\1dynamic-table-/g'
```

Then the bare root class, which the pattern above deliberately leaves alone:
`.dt` becomes `.dynamic-table`, and a `'root' => 'dt dt-brand'` theme slot
becomes `'root' => 'dynamic-table dynamic-table-brand'`.

Untouched: the Blade directives, every config key, every PHP class, property
and method, the `dynamic-table:*` DOM events, the `dynamic-table::*`
translation namespace, and the saved-view format — no view needs migrating.

## The table's own height

`dynamic-table.table.max_height` (default `'70vh'`) gives each table its own
scroll area, which is what makes the sticky header stick: a header can only
stay put inside a box that scrolls. A config file published before this key
existed simply lacks it, and tables then keep page-flow height with a header
that scrolls away. Add it:

```php
'table' => [
    'max_height' => '70vh',   // null or 'none' for page-flow height
],
```

Per table, `protected ?string $maxHeight = '50vh';` overrides it.

## Saved views are user data

Saved views are treated as data that must survive upgrades:

- every configuration carries a `version` field
- when the shape changes, old configurations are migrated on read
- a view referencing a field that no longer exists degrades — the invalid part
  is dropped, a warning is surfaced, the table still renders

A release will never silently discard saved views. If a migration cannot be
performed automatically, it will be documented here with a command to run.

## After any upgrade

```bash
php artisan dynamic-table:clear
php artisan migrate
```

The first clears cached metadata and the table registry; the second applies any
new migration. Both are safe to run when nothing changed.

## 1.0.0

First release. Nothing to upgrade from.

---

## Coming from another package

### From Yajra DataTables

You will not need the JavaScript half at all. A controller that built a
`DataTables::of(...)` response and a matching JS column definition becomes:

```php
class UsersTable extends DynamicTable
{
    protected string $model = User::class;
}
```

```blade
@dynamicTable(UsersTable::class)
```

Notes:

- `addColumn`/`editColumn` closures map to a column's `render` option (set
  `'raw' => true` if you return HTML, and escape what you interpolate).
- Relationship columns are `'department.name'` — eager loading is automatic, so
  drop your `with()` juggling.
- Server-side processing is the only mode; there is no client-side mode to
  configure.

### From Laravel PowerGrid

The mental model is similar, the ceremony is not.

- `Column::make('Name', 'name')->searchable()->sortable()` → `'name'`.
  Sortability and searchability are inferred from the type; override only when
  you disagree.
- `PowerGrid::fields()` → nothing, unless you want a `render` closure.
- `filters()` → nothing. The filter builder is generated from model metadata.
- `<livewire:users-table />` → `@dynamicTable(UsersTable::class)`.
- `datasource()` → `query()`, which receives and returns an Eloquent builder.
- Actions become `BulkAction::make(...)`.

The largest behavioural difference: DynamicTable does not re-render through
Livewire, so there is no component state to think about and no
`wire:model` interplay. If you were relying on Livewire events from the grid,
listen for the `dynamic-table:*` DOM events instead.

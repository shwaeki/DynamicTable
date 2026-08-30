# Upgrade guide

## Versioning promise

Laravel DynamicTable follows semantic versioning against its **public API**,
which is deliberately small:

- the `DynamicTable` base class and its documented properties and methods
- `BulkAction`, `Theme`, the events, `DynamicTableView`
- the Blade directives and the config file
- the `data-dt-*` DOM hooks and the `dynamic-table:*` DOM events

Anything under `Metadata`, `Query`, `Filters`, `Columns`, `Support` or
`Http\Controllers`, and the JSON endpoint shape, is **internal** and may change
in a minor release. See [docs/extending.md](docs/extending.md).

## Unreleased: a new config key

`dynamic-table.table.max_height` (default `'70vh'`) gives each table its own
scroll area, which is what makes the sticky header stick. A config file
published before this key existed simply lacks it, and tables then keep the old
page-flow height with a header that scrolls away. Add it:

```php
'table' => [
    'max_height' => '70vh',   // null or 'none' for the previous behaviour
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

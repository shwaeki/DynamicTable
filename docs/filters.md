# Search and filters

## Global search

On by default. It searches a small, safe set of columns rather than every text
column in the schema:

```php
protected array $searchable = ['name', 'email', 'department.name'];
```

With no list, the package takes the first
`config('dynamic-table.search.max_auto_columns')` (default 6) visible text-ish
columns. Relationship paths are supported and compile to `whereHas`.

Input is debounced (350 ms by default) and `%` and `_` are escaped so a user
cannot widen their own match.

> `LIKE '%term%'` cannot use a standard B-tree index. For tables in the millions
> of rows, point `searchable` at indexed prefix-searchable columns, or push
> search to a dedicated index (Scout, Meilisearch, `MATCH … AGAINST`) inside
> `query()`. See [Performance](performance.md).

## Column search

Opt in with the `column_search` feature to get a filter row under the headers.
Each input is typed: text columns use `contains`, numbers use equality, dates
use `whereDate`, booleans and enums use exact matching.

## The filter builder

Enabled by default. It is the Dynamics-style condition tree:

```
Where
  ( Department Name   contains    IT
    OR
    Department Name   contains    HR )
  AND
    Status            equals      Active
  AND
    Created At        this month
```

Groups nest up to `config('dynamic-table.security.max_filter_depth')` (4) and a
request may carry `security.max_filters` (40) conditions.

The available fields come from the same metadata engine that powers the column
picker, grouped by model and relationship, respecting `$hiddenColumns`,
`$allowedColumns` and `$relationDepth`. The catalogue is fetched lazily the
first time the panel opens, so a table that never filters never pays for
relationship introspection.

## Operators by type

| Type | Operators |
|---|---|
| Text | equals, not equals, contains, does not contain, starts with, ends with, is any of, is empty, is not empty |
| Number | equals, not equals, >, ≥, <, ≤, between, is any of, is empty, is not empty |
| Date | equals, before, after, between, today, yesterday, this/last/next week, this/last/next month, this/last year, in the last N days, in the next N days, is empty, is not empty |
| Boolean | equals, is empty, is not empty |
| Enum | equals, not equals, is any of, is none of, is empty, is not empty |
| Relationship | equals, not equals, is any of, is none of, is empty, is not empty |
| JSON | contains, does not contain, is empty, is not empty |

The operator list is derived from the field type; the server re-checks it, so a
forged operator is rejected rather than applied.

## Relationship semantics

- `department.name equals IT` → `whereHas('department', name = 'IT')`
- `department.name not equals IT` → `whereDoesntHave('department', name = 'IT')`,
  which **includes rows with no department**. That is usually what people mean
  by "not IT"; if you need the stricter reading, add an `is not empty` condition.
- `department.name is empty` → no department, or a department with a null name.

Relationship filters never join, so rows are never duplicated and no `DISTINCT`
is needed.

## Filter values for relationships

The value input for a relationship or enum field is a searchable remote select.
It is backed by a paginated `DISTINCT` query — the package never runs
`Department::all()`, even for a table with 100,000 departments.

## Programmatic filters

The base query is yours:

```php
public function query(Builder $query): Builder
{
    return $query->where('tenant_id', auth()->user()->tenant_id);
}
```

Or with scopes:

```php
protected array $scopes = ['active'];
```

`query()` runs before anything the user can influence, so it is a hard boundary:
no filter, sort, selection or inline edit can escape it. See
[Security](security.md).

## Presets

Developer-defined views appear alongside saved ones:

```php
public function presets(): array
{
    return [
        'active' => [
            'name' => 'Active users',
            'default' => true,
            'filters' => [
                'logic' => 'and',
                'conditions' => [
                    ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
                ],
            ],
        ],
    ];
}
```

## Invalid filters degrade

A filter naming a field that no longer exists is dropped, a warning is returned
in the payload, and the table renders. A stale saved view never breaks a page.

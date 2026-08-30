# Columns

## Automatic discovery

With no `columns()` method, DynamicTable inspects the model and picks a useful
default set:

- every database column, minus the primary key and housekeeping timestamps
  (`updated_at`, `deleted_at`, `created_by`, `updated_by`, `deleted_by`)
- minus anything in `$model->getHidden()`
- minus anything matching `config('dynamic-table.security.blocked_columns')`
  (`password`, `remember_token`, `*token*`, `*secret*`, `api_key`, `two_factor`, …)
- **foreign keys are replaced by their relation's label column**, so
  `department_id` becomes `department.name`
- JSON columns are available but hidden by default
- the first eight columns are visible; the rest are available in the column picker

## Type detection

| Source | Result |
|---|---|
| `'boolean'` cast, `tinyint(1)`, `is_*`/`has_*` | boolean → check/cross |
| `'integer'` / `'decimal:2'` cast, numeric column | number, right-aligned |
| `'date'` / `'datetime'` cast, date/timestamp column | localised date |
| Backed enum cast | enum → badge, with the enum's cases as filter options |
| `'array'` / `'json'` cast, json column | formatted JSON, hidden by default |
| `email`, `*_email` | mailto link |
| `url`, `*_url`, `*_link` | external link |
| `avatar`, `photo`, `image`, `logo`, `thumbnail` | thumbnail |
| `uuid`, `*_uuid` | uuid (equality filters only) |

If the enum has a `label()` method it is used for the display text.

## Declaring columns

`columns()` accepts four shapes, mixed freely:

```php
protected function columns(): array
{
    return [
        'name',                                       // just the field
        'department.name' => 'Department',            // field => label
        'salary' => [                                 // field => options
            'format' => 'currency:USD',
            'align'  => 'end',
        ],
        'status' => fn ($value, $record) => strtoupper($value),  // field => renderer
    ];
}
```

### Options

| Option | Default | Notes |
|---|---|---|
| `label` | humanised path | Also overridable via translations |
| `type` | detected | Any `FieldType` value |
| `visible` | first 8 columns | Hidden columns stay in the column picker |
| `sortable` | true for real columns | Computed accessors are never sortable |
| `searchable` | true for text-ish types | Feeds the global search |
| `filterable` | true | Removing it hides the field from the filter builder |
| `editable` | true when `inline_edit` is on | Never for computed or relational fields |
| `exportable` | true | |
| `format` | — | See below |
| `align` | by type | `start` / `center` / `end` |
| `width`, `minWidth`, `maxWidth` | — | Pixels |
| `wrap` | false | |
| `priority` | by position | Lower survives longer when columns collapse on a narrow screen. |
| `class` | — | Extra CSS classes on the cell |
| `render` | — | `fn ($value, $record, $column): string` |
| `raw` | false | Opt in to unescaped HTML — see the warning below |

### Formats

```
currency:USD    number:2      percent:1     bytes
date:d/m/Y      datetime      time          since
upper           lower         headline      truncate:40
```

Formatting happens on the server, so it follows the application locale and the
same values appear in exports.

### Custom rendering

```php
'status' => [
    'render' => fn ($value, $record) => $record->isOverdue()
        ? '<span class="text-red-600">Overdue</span>'
        : e($value),
    'raw' => true,
],
```

> **Escape it yourself.** Output is escaped by default; `raw` turns that off for
> that column only. Everything you interpolate into raw HTML must go through
> `e()`.

## Relationship columns

```php
'department.name'
'department.manager.name'   // needs $relationDepth = 2
```

Relations are eager loaded automatically — one extra query for the whole page,
never one per row. Only singular relations (`belongsTo`, `hasOne`, `morphOne`)
can be flattened into a column.

```php
protected int $relationDepth = 2;   // capped by config security.max_relation_depth
```

## Computed attributes

Accessors listed in `$appends` are available as columns, and are marked
**computed**: they can be displayed and exported, but not sorted, searched,
filtered or edited, because they do not exist at the SQL level. The package
enforces this rather than producing a confusing query.

## Restricting exposure

```php
protected array $hiddenColumns = ['internal_notes'];      // never exposed
protected array $allowedColumns = ['name', 'email'];      // exhaustive allowlist
```

`$allowedColumns` applies everywhere at once: columns, the filter builder, the
column picker, sorting, search, import and export.

## Labels

```php
protected array $labels = ['department.name' => 'Team'];
```

Or through translations, which is better for multilingual apps:

```php
// lang/xx/dynamic-table/fields.php
return ['users' => ['department.name' => 'Team']];
```

Otherwise `created_at` becomes "Created At" and `department.name` becomes
"Department Name".

## The column header menu

Every header carries a chevron that opens the menu Dynamics 365 grids have —
the actions people actually want from a header, where they expect them:

| | |
|---|---|
| **Sort A→Z / Z→A** | Labelled by type: *1 to 9* for numbers, *oldest to newest* for dates |
| **Group by this column** | Needs the `grouping` feature |
| **Filter by this column** | Opens the filter builder with a condition on that column already added |
| **Column width** | Set it numerically, or just drag the edge of the header |
| **Move left / Move right** | Moves one position; the wording follows the reading direction, so in RTL "left" moves it the way it looks |
| **Hide column** | Needs `column_picker`; it stays available in the picker |

The menu appears when at least one of its actions is possible, and offers
exactly the items the enabled features support — it never presents an action the
table cannot perform, and never leaves a separator floating under nothing.

It is on by default. Turn it off like any other feature:

```php
protected array $features = ['-header_menu'];
```

Every item writes to the same table state as the panels do, so whatever a user
does from a header is what a saved view stores and what an export follows.

## Grouping

```php
protected array $features = ['grouping'];
```

Group from the header menu, or set it directly:

```php
$state['group'] = 'department__name';
```

Grouping is expressed as a **leading `ORDER BY`**, and the browser starts a new
heading row wherever the value changes. The database does the work; nothing is
ever loaded into PHP to be grouped, and grouping costs no extra query.

Computed accessors cannot be grouped, for the same reason they cannot be sorted.

## The column picker

Enable `column_picker` (implied by `views`, `column_reordering` and
`column_resizing`) to let users choose, reorder and resize columns. Order and
widths are part of table state, so they are what a saved view stores and what an
export produces.

Reordering works with a mouse (drag) and with the keyboard (`Alt` + arrow keys).

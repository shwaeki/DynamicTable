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

## Built-in cell renderers

The handful of presentations people write the same render closure for on every
project ship as formats:

```php
protected function columns(): array
{
    return [
        'avatar'   => ['format' => 'avatar:Customer'],   // round thumbnail, argument is the alt text
        'progress' => ['format' => 'progress:500'],      // bar; the argument is what counts as full
        'rating'   => ['format' => 'rating'],            // stars, out of 5 by default
        'trend'    => ['format' => 'sparkline'],         // an array of numbers becomes a line
        'tags'     => ['format' => 'chips:3'],           // array or comma-separated, capped at 3 + "n more"
        'runtime'  => ['format' => 'duration'],          // seconds as "1h 20m"
    ];
}
```

Each is one word, and they compose with everything a column already has —
alignment, width, visibility, the picker, saved views.

Three things worth knowing about how they work:

- **They render on the server**, as inline SVG or a span with a class. There is
  no chart library, no client work, and they survive with JavaScript disabled.
- **A column using one is marked raw automatically**, because the output is
  markup. You do not add the flag.
- **The text lives inside the markup.** A progress bar contains "10 / 500", a
  rating contains "3.7 / 5". Exports strip the tags and drop the decorative
  parts, so a CSV of a rating column reads `3.7 / 5` rather than four stars or
  a lump of HTML.

Colour comes from the CSS tokens, so all of them follow the theme and the
colour scheme without a per-theme variant.

## Summary row

Opt in per column, and an aggregate appears under it:

```php
'total' => ['format' => 'currency:USD', 'summary' => 'sum'],
'price' => ['summary' => 'avg'],
'id'    => ['summary' => 'count'],
```

`sum`, `avg`, `min`, `max` and `count`. `'summary' => true` means `sum`.

It covers the **whole filtered result, not the page** — a total that changed
when you turned the page would not be a total. Filtering therefore changes it
and paging does not. It costs one extra aggregate query, and only when at least
one visible column asked for it.

The value is formatted the way that column's values are, so a total under a
currency column reads as currency. `count` is the exception: it counts rows, so
it is always a plain number.

Computed accessors and relationship columns are refused, for the same reason
they cannot be sorted: the aggregate has to happen in SQL, over a real column,
without a join.

The row is `<tfoot>` — which is where a table's totals belong, is announced as
such by screen readers, and prints on every sheet.

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

Every header carries a gear that opens the menu Dynamics 365 grids have — the
actions people actually want from a header, where they expect them:

| | |
|---|---|
| **Sort A→Z / Z→A** | Labelled by type: *1 to 9* for numbers, *oldest to newest* for dates |
| **Group by this column** | Needs the `grouping` feature |
| **Filter by this column** | Opens a small panel: an operator, a value, Apply and Clear |
| **Column width** | Set it numerically, or just drag the edge of the header |
| **Move left / Move right** | Moves one position; the wording follows the reading direction, so in RTL "left" moves it the way it looks |
| **Hide column** | Needs `column_picker`; it stays available in the picker |

**With the menu on, clicking a header opens the menu instead of sorting.** Both
sort directions are in the menu, and a header that both sorts and opens a menu
makes one of the two an accident. With the menu off, the header sorts on click
as usual.

The menu appears when at least one of its actions is possible, and offers
exactly the items the enabled features support — it never presents an action the
table cannot perform, and never leaves a separator floating under nothing.

### Filter by this column

"Filter by this column" opens a popover anchored to that header — one operator,
one value, **Apply** and **Clear** — rather than the whole builder, which would
be a heavy answer to "only show me the shipped ones".

It is not a second kind of filter. It writes a condition into the same tree the
filter panel edits, so what you set here appears in that panel, counts towards
the toolbar badge, is stored in a saved view, and is compiled by the same code
on the server. Setting it again replaces the condition on that column rather
than stacking another one; **Clear** removes it.

A column that a condition is about is marked in its header — a small funnel and
a tinted background — so a filter set from a header is visible without opening
anything. The marker is derived from the filter tree, not tracked beside it, so
filters from the builder, the header and a saved view all show up identically.

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

The two features are separate on purpose: `column_picker` decides **which**
columns are shown, `column_reordering` decides **what order** they are in. Most
tables want the first without the second — let people pick the columns they
need, but keep the order the designer chose. `column_reordering` implies
`column_picker`, since the place you reorder columns is that same panel.

### Adding a column that was never declared

The panel is modelled on Dynamics' *Edit columns*: the list is the columns the
view **has**, in the order it has them, and **Add column** opens the whole field
catalogue — the model's own fields *and* those of its singular relations, the
way a Dynamics view can add any column of the entity or its lookups.

```php
protected function columns(): array
{
    return ['name', 'email', 'status'];   // what the table starts with
}
```

A user can still add `department.name`, or `department.company.country` if
`$relationDepth` reaches that far. Nothing has to be declared for it to be
available, and a column added that way is a first-class column: it sorts, it
groups, it column-searches, it exports, and a saved view remembers it.

The catalogue is the same one the filter builder uses, fetched lazily the first
time a panel opens, so a table nobody customises never pays for it.

**What stops a user adding whatever they like** is the same three gates that
already governed filtering and sorting, applied on the server to every key that
arrives:

1. The path must resolve through the metadata engine — so it exists, is not in
   the model's `$hidden`, and is within the relation depth.
2. It must not be in `$hiddenColumns`.
3. It must pass `$allowedColumns` when that list is set.

A crafted key reaches nothing that a filter could not already have reached, and
anything that fails is dropped rather than throwing — a saved view naming a
since-removed field degrades instead of breaking the page.

To keep the picker to an exact list, that is what `$allowedColumns` is for:

```php
protected array $allowedColumns = ['name', 'email', 'department.name'];
```

# Features

Every capability in the package is a named feature. This page is the complete
reference: what each one does, what it costs, what it turns on with it, and
which JavaScript module it loads.

The rule behind the whole list is worth stating once, because it is what makes
a long list safe:

> A disabled feature renders no UI, registers no state, loads no JavaScript,
> runs no queries, and rejects its endpoint with an error. The guarantee is
> enforced on the server, not documented.

So a table that enables nothing is not "a table with the extras hidden" — it is
a smaller program.

## Declaring features

```php
protected array $features = [
    'views',
    'export',
    'bulk-actions',   // hyphens, camelCase and snake_case all work
    '-search',        // switch a default off
];
```

`'only'` as the first entry starts from nothing rather than from the defaults:

```php
protected array $features = ['only', 'pagination'];   // a static list, no JS panels
```

The constants on `Shwaeki\DynamicTable\Support\Feature` are the same strings, if
you prefer them:

```php
use Shwaeki\DynamicTable\Support\Feature;

protected array $features = [Feature::BULK_EDIT, Feature::ROW_DETAIL];
```

## On by default

These cost nothing beyond what a table already does, so they are on unless you
switch them off with `-name`.

| Feature | What it does | Cost |
|---|---|---|
| `search` | The toolbar search box, across the searchable columns. | One `WHERE` group. No extra query. |
| `filters` | The nested AND/OR filter builder. | The field catalogue is fetched lazily the first time the panel opens. |
| `sorting` | Sortable headers, up to three levels. | `ORDER BY`. Relationship sorting uses a correlated subquery, never a join. |
| `pagination` | Page size control, page list, and the range summary. | One `COUNT(*)` — see [Performance](performance.md). |
| `responsive` | Small-screen strategy: collapse, cards or scroll. | One small module, and only in `collapse`/`cards` mode. |
| `header_menu` | The Dynamics-style column menu: sort, filter, group, width, move, hide. | One module, loaded up front. |

## Selecting and acting

| Feature | What it does | Implies | Module |
|---|---|---|---|
| `selection` | Row checkboxes, shift-click ranges, and "select all matching" as a *mode* rather than a list of ids. | — | `actions` |
| `bulk_actions` | Your `actions()` entries, run over the selection on the server. | `selection` | `actions` |
| `bulk_edit` | Set the same columns on every selected record, validated once and applied record by record in chunks. | `selection` | `actions` |
| `row_actions` | Per-row buttons from `rowActions()`; links or server handlers, with per-record visibility. | — | `actions` |
| `toolbar_actions` | Your own buttons in the toolbar from `toolbar()`, optionally collecting inputs first. | — | `actions` |

All four action features share one rule: **the button having been rendered is
never treated as permission.** The endpoint re-resolves the action, re-checks
the ability, and for row and bulk work re-checks each record.

```php
public function toolbar(): array
{
    return [
        ToolbarAction::make('sync')
            ->label('Sync catalogue')
            ->icon('↻')
            ->alignStart()                 // beside the search box
            ->ability('sync')              // gate, checked again on the server
            ->handle(fn (): string => 'Catalogue synced.'),
    ];
}
```

Bulk edit needs nothing but editable columns:

```php
protected array $features = [Feature::BULK_EDIT];

protected function columns(): array
{
    return [
        'name'   => ['editable' => true],
        'status' => ['editable' => true],
        'sku'    => ['editable' => false],   // identifiers stay read-only
    ];
}
```

Only ticked fields are sent, so a bulk edit of one column cannot blank another.

## Writing

| Feature | What it does | Implies | Module |
|---|---|---|---|
| `inline_edit` | Double-click a cell, or Enter/F2 on the keyboard. Enter saves and moves down, Tab across, Escape cancels. | — | `inline-edit` |
| `create` | A blank row at the top of the table, saved as one request. | `inline_edit` | `inline-edit` |
| `spreadsheet` | Paste a block from Excel over the grid, with a diff to confirm. | `inline_edit`, `selection` | `spreadsheet` |
| `import` | CSV/XLSX upload, mapped to columns, chunked and transactional. | — | `transfer` |
| `export` | Streaming CSV/XLSX of the current view — the filters, not the page. | — | `transfer` |

Inline editing, inline creating and bulk editing share one normaliser, so a
value rejected by one is rejected by all three, and `rules()` applies to
whichever way the write arrives:

```php
public function rules(): array
{
    return [
        'name'  => ['required', 'string', 'max:120'],
        'price' => ['required', 'numeric', 'min:0'],
    ];
}
```

`create` also uses `newRecordDefaults()` for the columns the blank row does not
ask for, so the model is never saved half-built:

```php
public function newRecordDefaults(): array
{
    return ['status' => ProductStatus::Draft, 'is_featured' => false];
}
```

## Reading

| Feature | What it does | Implies | Module |
|---|---|---|---|
| `row_detail` | A chevron per row that opens a panel underneath it, fetched on demand from `rowDetail()`. | — | `detail` |
| `grouping` | Group rows by a column, with a heading row per value. | — | — |
| `column_picker` | Choose visible columns, from the full metadata tree. | — | `columns` |
| `column_reordering` | Drag columns into a new order. | `column_picker` | `columns` |
| `column_resizing` | Drag the edge of a header, Excel style. | `column_picker` | `columns` |
| `column_search` | A search input under each column header. | — | — |
| `views` | Saved views: named, versioned, shareable, with a default per user. | `column_picker` | `views` |
| `soft_deletes` | A trashed filter, restore and force-delete. | — | — |
| `url_state` | Mirrors search, page, sort and filters into the query string. | — | — |

`rowDetail()` may return a string, an `HtmlString`, or a Blade view:

```php
public function rowDetail(Model $record): mixed
{
    return view('partials.order-detail', ['order' => $record->load('items')]);
}
```

The record is re-fetched through the table's own base query and checked against
the `view` ability, so a detail can never be read for a row the table would not
have shown. The panel is fetched the first time it is opened and cached until
the rows change.

## Presentation and scale

| Feature | What it does | Module |
|---|---|---|
| `sticky_columns` | Freeze leading columns — and optionally the row actions — while the table scrolls sideways. | `sticky` |
| `facets` | Counts beside filter values: "Shipped (1,204)". | — |

```php
protected array $features = [Feature::STICKY_COLUMNS];

/** Frozen in this order, from the leading edge. */
protected array $stickyColumns = ['reference', 'customer__name'];

/** Row buttons freeze against the opposite edge. */
protected bool $stickyActions = true;
```

Offsets are measured in the browser and written as *logical* insets, so the
same declaration freezes the correct edge in RTL.

Facets are opt-in per column, because each one is a grouped query:

```php
protected array $features = [Feature::FACETS];

protected array $facets = ['status'];
```

The counts honour the current search and filters **except** any condition
already set on that same column — otherwise picking "Shipped" would report
every other status as zero. Computed and relationship columns are refused: a
count has to be one `GROUP BY` on a real column.

Infinite scrolling is not a feature flag but a pagination style, because it
changes how the same paged endpoint is presented rather than what it can do:

```php
protected string $pagination = 'infinite';
```

Pages are appended instead of replaced, an `IntersectionObserver` on a sentinel
below the last row asks for the next one, and no `COUNT(*)` runs — the footer
reports the range rather than an invented total.

## Implications

Declaring a feature that needs another turns that one on too, so the same idea
is never configured twice:

| Declared | Also enabled |
|---|---|
| `bulk_actions` | `selection` |
| `bulk_edit` | `selection` |
| `create` | `inline_edit` |
| `spreadsheet` | `inline_edit`, `selection` |
| `views` | `column_picker` |
| `column_reordering` / `column_resizing` | `column_picker` |

## What each feature loads

The core module is the only JavaScript on the page up front. Everything else is
imported the first time it is needed, from the same versioned URL, so a table
that never opens a filter never downloads the filter builder.

| Module | Loaded by |
|---|---|
| `actions` | `selection`, `bulk_actions`, `bulk_edit`, `row_actions`, `toolbar_actions` |
| `inline-edit` | `inline_edit`, `create` |
| `filters` | `filters` |
| `columns` | `column_picker`, `column_reordering`, `column_resizing` |
| `views` | `views` |
| `transfer` | `export`, `import` |
| `spreadsheet` | `spreadsheet` |
| `detail` | `row_detail` |
| `sticky` | `sticky_columns` |
| `responsive` | `responsive` (collapse and cards modes) |
| `header-menu` | `header_menu` |

## Endpoints

One route per capability, all POST, all resolving the table from its key and
re-deriving everything else server-side:

| Route name | Feature | Guarded by |
|---|---|---|
| `dynamic-table.data` | — | The table's own query and allowlists. |
| `dynamic-table.fields` | `filters` | Hidden and allowed column paths. |
| `dynamic-table.options` | `filters` | Same, plus the facet opt-in. |
| `dynamic-table.edit` | `inline_edit` | `update`, per record. |
| `dynamic-table.create` | `create` | `create`. |
| `dynamic-table.bulk-edit` | `bulk_edit` | `update`, re-checked per record. |
| `dynamic-table.action` | `bulk_actions` | The action's own ability. |
| `dynamic-table.row-action` | `row_actions` | The action's ability, against that record. |
| `dynamic-table.toolbar-action` | `toolbar_actions` | The action's ability. |
| `dynamic-table.row-detail` | `row_detail` | `view`, and the table's base query. |
| `dynamic-table.export` / `import` | `export` / `import` | `export` / `import`. |
| `dynamic-table.views.*` | `views` | Ownership, and sharing. |

## See also

- [The table class](tables.md) — every property and hook in one page.
- [Editing and actions](editing.md) — inline editing, bulk actions, validation.
- [Themes](themes.md) — including the framework-free `minimal` and `bordered`.
- [Performance](performance.md) — counting, estimates and large tables.
- [Security](security.md) — the allowlist model these endpoints rely on.

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
    'saved_views',
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
| `relations` | Lets the filter builder and the column picker reach through the model's relations. | The catalogue costs one introspection pass, cached. |

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
| `inline_create` | A blank row at the top of the table, saved as one request. | `inline_edit` | `inline-edit` |
| `import` | CSV/XLSX upload, mapped to columns, chunked and transactional. | — | `transfer` |
| `export` | Streaming CSV/XLSX of the current view — the filters, not the page. | — | `transfer` |
| `print` | A printable page for the current view, from an editable Blade template. | — | — |

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

`inline_create` also uses `newRecordDefaults()` for the columns the blank row does not
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
| `row_reorder` | Drag a row by its handle to write its position. | — | `reorder` |
| `pinned_rows` | A star per row; pinned rows sort to the top for that viewer. | — | `pins` |
| `grouping` | Group rows by a column, with a heading row per value. | — | — |
| `column_picker` | *Which* columns are shown: the panel, and adding any field the metadata reaches. | — | `columns` |
| `column_reorder` | *What order* they are in: drag handles in the panel, and Move left/right in the header menu. | — | `columns` |
| `column_resize` | Drag the edge of a header, Excel style. No panel involved. | — | `columns` |
| `column_search` | A search input under each column header. | — | — |
| `saved_views` | Named, versioned, shareable snapshots of table state, with a default per user. | `column_picker` | `views` |
| `url_state` | Mirrors search, page, sort and filters into the query string. | — | — |
| `remember_state` | Reopens the table the way this viewer last left it, with nothing to save or name. | — | — |

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

## Reordering rows

```php
protected array $features = ['row_reorder'];

protected string $reorderable = 'sort_order';

protected array $defaultSort = ['sort_order' => 'asc'];
```

A grip appears at the start of each row; drag it — or focus it and press
**Alt + up/down** — and the position column is written on drop.

**Only while the table is sorted by that column.** Under any other sort,
dropping a row between two others describes a position the table is not
showing, so the handles disappear rather than doing something surprising. The
same rule is enforced on the server, which refuses a drag arriving under the
wrong sort.

What a drag writes is worth knowing, because it decides what it costs:

> The rows on the page hold a set of position values. The drag does not invent
> new numbers — it **permutes which row sits in which value that was already
> there**.

So a drag on page 40 costs exactly what a drag on page 1 costs, nothing outside
the page is renumbered, and no new value can collide with a row the reader
cannot see. Rows whose position is null or duplicated are given values above
the largest one on the page, which leaves every properly-positioned row where
it was.

The endpoint re-fetches every record through the table's own base query and
checks `update` on each one, so a drag can never move a row the table would not
have shown. A refused drag is not half-applied: the whole reorder is rejected
and the table refetches, rather than leaving an order that is neither the old
one nor the one that was asked for.

## Pinned rows

```php
protected array $features = ['pinned_rows'];
```

A star per row. Pinned rows sort above everything else — above the group
heading too — for that viewer and nobody else.

It is one `ORDER BY CASE`, not a second query or a union, so the pinned row is
simply at the top of page 1 rather than *also* still on page 9: one result, one
count, one page, and each row appears exactly once.

The list lives in the session, like [remembered state](#), and for the same
reason: it needs no table and no migration, it is private by construction, and
it lasts as long as the sitting does. A pin is a working note — "these three
while I deal with them" — not a document. Saved views are the durable,
nameable, shareable version of the same idea.

Up to 50 rows, oldest dropped first. An unbounded list in the session would be
an unbounded `IN` clause in the query, and a cap that refused new pins would
leave the reader pressing a button that does nothing.

## The three column features

`column_picker`, `column_reorder` and `column_resize` are one panel and one
JavaScript module, so they look like one feature and are often mistaken for it.
They are three because they hand the reader three different powers, and plenty
of tables want the first without the others:

| Enabled | The reader can |
|---|---|
| *(none)* | nothing — the columns are exactly what the table declares |
| `column_picker` | choose **which** columns are shown, and add any field the metadata reaches |
| `+ column_reorder` | also choose **what order** they are in — drag handles in the panel, Move left/right in the header menu |
| `+ column_resize` | also choose **how wide** each one is, by dragging the header edge |

None of them implies another. Ask for exactly what you want:

```php
protected array $features = ['column_reorder'];   // reorder, and nothing else
```

That table opens the panel with the columns listed and draggable, and with no
Add column link and no remove buttons — because it was not asked for them. A
table with only `column_resize` gets no panel at all: its handles live in the
header.

There is no download to save by leaving them off — all three live in the same
`columns` module. The reason to leave one off is that you do not want the
reader changing that particular thing.

## Soft deletes

**There is no feature for this**, because Eloquent already says all three
things and the package would only be wrapping a builder method in a flag:

```php
use Illuminate\Database\Eloquent\Builder;

public function query(Builder $query): Builder
{
    return $query->withTrashed();     // or ->onlyTrashed()
}
```

Leave `query()` alone and the model's own global scope hides trashed rows,
which is the default anyone would expect.

What the package does contribute is recognising a trashed row and striking it
through — automatically, whenever the model uses `SoftDeletes`, whether or not
you reached those rows deliberately.

Restore and force-delete are ordinary row actions:

```php
public function rowActions(): array
{
    return [
        RowAction::make('restore')
            ->icon('↺')
            ->visible(fn (Product $product): bool => $product->trashed())
            ->handle(fn (Product $product) => $product->restore()),
    ];
}
```

To let the *reader* choose between live and trashed, make it a declared
parameter — that is what parameters are for:

```php
protected array $params = ['show' => 'live'];

public function query(Builder $query): Builder
{
    return $this->param('show') === 'trashed' ? $query->onlyTrashed() : $query;
}
```

## Reaching through relations

On by default: the filter builder and the column picker offer the fields of the
model's singular relations alongside its own, so a reader can add `Department`
to an employee table, or filter orders by `customer.country`, without anyone
having declared those columns.

Some tables should not offer that — a screen for one narrow job, a model whose
relations lead somewhere the reader has no business browsing. Switch it off:

```php
protected array $features = ['-relations'];
```

With it off, three things answer "no depth" at once: the field catalogue behind
both panels, a column the picker would otherwise add on demand, and a filter
condition on a relation path.

**A relation column the table itself declares is unaffected.** This governs
what the *reader* may reach for, not what you may show them:

```php
protected array $features = ['-relations'];

protected function columns(): array
{
    // Still shown, still sorted, still exported.
    return ['reference', 'customer.name' => 'Customer', 'total'];
}
```

`$relationDepth` remains the way to allow relations but limit how far they go;
this is the switch for allowing none at all.

## Presentation and scale

| Feature | What it does | Module |
|---|---|---|
| `sticky_columns` | Freeze leading columns — and optionally the row actions — while the table scrolls sideways. | `sticky` |
| `filter_counts` | Counts beside filter values: "Shipped (1,204)". | — |

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
protected array $features = [Feature::FILTER_COUNTS];

protected array $filterCounts = ['status'];
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

### `column_picker` vs `column_reorder`

They share a panel, which is why they are easily mistaken for one feature. They
are not: each contributes its own controls to that panel and nothing else.

| | `column_picker` | `column_reorder` |
|---|---|---|
| Answers | *Which* columns? | *In what order?* |
| Contributes | Add column, the remove buttons, the title "Choose columns" | Drag handles, `Alt`+arrows, Move left/right in the header menu, the title "Arrange columns" |
| Alone | The panel lists the columns and lets you add and remove them; the list cannot be dragged | The panel lists the columns and lets you drag them; there is nothing to add or remove |
| Neither | No panel at all — the columns are what `columns()` said |

Neither implies the other. Turning on the picker alone is the common case: let
people choose the columns they need, but keep the order the designer chose.

## Printing

```php
protected array $features = [Feature::PRINT];
```

A **Print** button opens a page built for paper rather than for a screen: the
header repeats on every sheet, rows never split across two, and the search,
filters and sort that produced the page are named at the top — so the printout
still means something a week later.

It is shaped like an export, not like a screenshot. The same scopes (`page`,
`view`, `all`, `selected`), the same columns, the same server-side formatting,
so a printout and a CSV of the same view agree. `print.max_rows` (2000 by
default) caps it, because past that the reader wants a spreadsheet.

By default the page prints itself: the dialog opens once images and fonts have
loaded, and the tab closes when the dialog is dismissed — printed or cancelled,
the page has done its job either way. The button opens the tab with a script
rather than as a plain link, because a tab may only close itself if a script
opened it.

While you are working on the template, add `?auto=0` to any print URL to get
the page without the dialog. Turn the behaviour off everywhere with
`print.auto => false`.

**The template is yours.** Publish it and edit it:

```bash
php artisan vendor:publish --tag=dynamic-table-views
# resources/views/vendor/dynamic-table/print.blade.php
```

Or point one table at its own:

```php
protected ?string $printView = 'reports.orders-print';
```

The view is handed `$title`, `$columns`, `$rows`, `$summaries`, `$meta`,
`$scope`, `$printedAt`, `$classes` and `$direction` — everything resolved, so
the template only lays it out. Bootstrap and Tailwind class maps are supported:
the template's own CSS stands alone, and the framework's stylesheet is loaded
alongside it so a custom class map still looks like itself on paper. Control
that with `print.stylesheets`, or `$printStylesheets` per table.

## Remembering how a table was left

```php
protected array $features = [Feature::REMEMBER_STATE];
```

The table reopens the way this viewer last had it — same columns, sort, page
size and filters — with nothing to name and nothing to save.

This is not saved views, deliberately. A saved view is a *document*: named,
shareable, versioned, kept because someone decided it should be. Remembered
state is a *habit*: unnamed, private, and only ever the last thing you did. It
lives in the session, so it needs no table and no migration.

The page number and the selection are **not** remembered: returning to page 47,
or to a selection made yesterday, is disorienting rather than helpful.

Precedence, weakest first: table defaults → default saved view → remembered
state → URL parameters → whatever the Blade call passed. A link someone sent
you therefore beats your own habit, or shared links would stop meaning
anything.

## Implications

Declaring a feature that needs another turns that one on too, so the same idea
is never configured twice:

| Declared | Also enabled |
|---|---|
| `bulk_actions` | `selection` |
| `bulk_edit` | `selection` |
| `inline_create` | `inline_edit` |
| `saved_views` | `column_picker` |

## What each feature loads

The core module is the only JavaScript on the page up front. Everything else is
imported the first time it is needed, from the same versioned URL, so a table
that never opens a filter never downloads the filter builder.

| Module | Loaded by |
|---|---|
| `actions` | `selection`, `bulk_actions`, `bulk_edit`, `row_actions`, `toolbar_actions` |
| `inline-edit` | `inline_edit`, `inline_create` |
| `filters` | `filters` |
| `columns` | `column_picker`, `column_reorder`, `column_resize` |
| `views` | `saved_views` |
| `transfer` | `export`, `import` |
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
| `dynamic-table.create` | `inline_create` | Creating a row. |
| `dynamic-table.bulk-edit` | `bulk_edit` | `update`, re-checked per record. |
| `dynamic-table.action` | `bulk_actions` | The action's own ability. |
| `dynamic-table.row-action` | `row_actions` | The action's ability, against that record. |
| `dynamic-table.toolbar-action` | `toolbar_actions` | The action's ability. |
| `dynamic-table.row-detail` | `row_detail` | `view`, and the table's base query. |
| `dynamic-table.export` / `import` | `export` / `import` | `export` / `import`. |
| `dynamic-table.views.*` | `saved_views` | Ownership, and sharing. |

## See also

- [The table class](tables.md) — every property and hook in one page.
- [Editing and actions](editing.md) — inline editing, bulk actions, validation.
- [Themes](themes.md) — `custom`, `bootstrap` and `tailwind`.
- [Performance](performance.md) — counting, estimates and large tables.
- [Security](security.md) — the allowlist model these endpoints rely on.

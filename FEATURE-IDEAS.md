# Feature ideas — Laravel DynamicTable

Ideas, not commitments. Every entry below is a gap that does **not** exist in
the package today (checked against `src/Support/Feature.php`, `Operator.php`,
`CellRenderers.php`, `Theme.php`, `config/dynamic-table.php` and the 48 demo
tables), with a note on where it would plug in.

> **Status.** Items 2, 3, 4, 8, 9, 13, 17, 18, 19, 20, 21, 31, 35, 39 are built and in the
> package — see the CHANGELOG. The rest of this file is still a list of ideas.

## How to read this

| Tier | Meaning |
|---|---|
| **P1** | High. Fills a real gap, fits the zero-configuration premise, and most of the plumbing already exists. |
| **P2** | Medium. Clear value, more design work, or a narrower audience. |
| **P3** | Low. Nice to have; only after the tiers above. |

Effort is **S** (a day or two), **M** (a week), **L** (a release of its own).

Numbers are stable — quote them in issues and commits. Nothing here is
scheduled; the order inside a tier is roughly the order I would build them.

Two rules the whole list is written against, because they are what make the
package what it is:

- **The server owns state.** Anything a reader chooses passes through
  `TableState` and is re-checked. The UI having rendered a control is never
  treated as permission.
- **Two renderers must mirror each other.** Anything added to
  `resources/views/table.blade.php` belongs in `resources/js/core.js`, or it
  appears only after the first refresh.

---

# P1 — High priority

## Data and query

### 1. Conditional row and cell formatting — M

Nothing in `src/` matches `rowClass`, `cellClass` or `rowAttributes`. Every grid
eventually needs "overdue rows in red, negative totals in amber", and today the
only way is a computed column that renders its own HTML.

Make it declarative, so the server sends a class *token* per row and cell rather
than markup and both renderers paint it:

```php
protected $rowStyles = [
    'danger' => ['due_at', '<', 'now'],
    'muted'  => ['status', '=', 'archived'],
];
```

Colour comes from CSS tokens, so it works in every theme and in dark mode.

**Where:** `Query/RowFormatter.php`, `Support/TablePayload.php`,
`table.blade.php`, `core.js`, a `row-{tone}` slot in `Support/Theme.php`.

### 2. Totals footer and per-group aggregates — M  ✅ built

`ColumnDefinition::$summary` already computes sum/avg/min/max/count, but only
once for the whole table. Two extensions on the same plumbing:

- a sticky totals row in the footer, over the filtered set;
- a subtotal on each `grouping` heading row.

This is the single change that makes the package read as a reporting grid rather
than a list.

**Where:** `Query/QueryEngine.php` (one extra aggregate query per group key),
`ColumnDefinition::carriesSummary()`, both renderers.

### 3. Aggregate filters and columns over `hasMany` — M  ✅ built

`relations` reaches *singular* relations only, so `orders_count > 5`,
`sum(orders.total) > 1000` and "customers with no invoices" cannot be said at
all. `withCount` / `withSum` / `withExists` paths in the metadata catalogue
unlock a whole class of admin questions, and `QueryEngine` already builds
correlated subqueries for relation sorting — same technique, different
aggregate.

**Where:** `Metadata/MetadataEngine.php`, `Metadata/RelationMetadata.php`,
`Query/QueryEngine.php`, `Filters/FilterEngine.php`.

### 4. Keyset (cursor) pagination for `infinite` mode — M  ✅ built

`paginationStyle()` already has an `infinite` mode, and it runs on offset
pagination. Where rows are being inserted, offset-based infinite scroll silently
duplicates and skips rows, and gets slower the further down you go. This is a
correctness bug waiting to be filed against the 100k demo, not only a
performance idea.

Needs a stable tiebreaker column appended to every sort.

**Where:** `Query/QueryEngine.php`, `Support/TableState.php` (a cursor instead
of a page number), `core.js`.

### 5. A pluggable search driver — M

`search` compiles to a `LIKE` group. That is the right default, and the first
wall a large table hits. A `searchDriver` seam with three implementations —
`like` (default), `fulltext` (MySQL `MATCH…AGAINST`, Postgres `tsvector`) and
`scout` — returning ids that `QueryEngine` intersects, keeps filters, sorting
and authorization exactly where they are.

**Where:** a `Contracts/SearchDriver` beside the existing spreadsheet contracts,
`QueryEngine`, the `search` key in `config/dynamic-table.php`.

### 6. Per-user timezone for datetime columns — S

Nothing in `src/` or `config/` matches `timezone`. `formats.date/time/datetime`
control the *shape* of a datetime, not its zone, so a UTC-storing application
shows UTC to every reader — and `today`, `this_week` and the rest of the
relative operators in `Operator.php` compute in the application's zone, not the
reader's. This is what produces the "why does my export say yesterday" report.

**Where:** `Support/DateFormat.php`, `Query/RowFormatter.php`,
`Filters/FilterEngine.php` (the relative operators), the export writers.

## Editing and actions

### 7. Queued bulk actions with progress — M

Bulk actions and bulk edit run inline, so "select all matching" over 50,000 rows
ends in a timeout and the reader never learns how far it got. The transfer
module already has every part needed: `Support/TransferProgress.php`, a polling
endpoint, and a progress UI in `transfer.js`. Reuse it — over a threshold,
dispatch, poll, and report "4,120 of 50,000, 3 failed".

**Where:** `Http/Controllers/ActionController.php`, `BulkEditController.php`, a
`BulkActionJob` beside `Modules/Export/ExportJob.php`, `actions.js`.

### 8. Optimistic concurrency on inline edit — S  ✅ built

Two people editing the same row silently overwrite each other. Send the row's
`updated_at` with the edit and refuse the write if it moved — "this row changed
since you loaded it, reload?" instead of a lost update. Cheap, invisible when
nothing is wrong, and exactly the kind of correctness the package advertises
elsewhere. Skips cleanly for models without timestamps.

**Where:** `Http/Controllers/EditController.php`, `inline-edit.js`.

### 9. Row reordering — M  ✅ built

Drag a row to write a position column:

```php
protected $reorderable = 'sort_order';
```

Common in every admin panel and impossible today. `columns.js` already owns
drag-and-drop, so the client half is largely written; the server half is one
endpoint that re-sequences *within the current filter* and refuses while a sort
other than the position column is active.

**Where:** a new `ReorderController`, `columns.js` / `core.js`, one new name in
`Feature.php`.

## Reading and navigation

### 10. Keyboard-first navigation and a command palette — M

`inline-edit.js` already handles Enter/Tab/F2/Escape. Extend it to arrow-key
cell focus across the whole grid, `/` to focus search, `?` for a shortcut sheet,
and `Ctrl+K` for a palette over columns, filters, saved views and actions. No
server work, no schema, and it is what makes a grid feel a tier above the other
Laravel table packages.

**Where:** a new `keyboard` module in `Feature::MODULES`, `ui.js`.

### 11. Deep link to a record — M

"Open the table at order 4821": work out which page that row falls on under the
current sort and filters, jump there, scroll to it and highlight it. Harder than
it looks — it is a rank query, not a lookup — and enormously useful, because
every "back to the list" link in an application wants it.

**Where:** `QueryEngine` (a `COUNT(*) WHERE sort < record` rank query),
`TableState`, `core.js`.

### 12. Auto-refresh and live updates — M

A poll interval, plus an optional broadcast channel that only says "stale" and
lets the table refetch on its own terms. `TransferProgress` and `transfer.js`
already poll, so this is a small module and a payload flag. It must never
refetch while a cell is being edited, a menu is open, or rows are selected.

**Where:** a new `live` module, `TablePayload`, `core.js`.

### 13. Row click navigation — S  ✅ built

`rowUrl(Model $record)` making the whole row a link: normal click navigates,
middle-click and Ctrl-click open a tab, and clicks on checkboxes, action buttons
and editable cells are excluded. Small, and it is the first thing people
hand-roll on top of the package. Render it as a real `<a>` overlay rather than a
JS click handler, so middle-click works and screen readers see a link.

**Where:** `DynamicTable.php`, both renderers.

## Output

### 14. PDF as a third transfer format — M

`print` renders a page; export writes CSV and XLSX. Add an optional
`DocumentWriter` contract beside `SpreadsheetWriter`, with dompdf or Browsershot
as a `suggest` in `composer.json` — exactly the pattern openspout and
phpspreadsheet already follow — rendering `print.blade.php` server-side. "Email
me the PDF" is the most-requested feature of every admin table.

**Where:** `Contracts/`, `Modules/Export/`, `Http/Controllers/TransferController.php`.

## Security and correctness

### 15. Column-level authorization — M

`authorize()` is per record and per ability; there is no way to say "only HR
sees `salary`". Enforce a per-column ability in `ColumnResolver` so the column is
absent from the payload, the export, the print view, the filter catalogue *and*
the column picker — one check, six places, none of them optional. The same
discipline as the existing feature guarantee, one level down.

**Where:** `Columns/ColumnResolver.php`, `DynamicTable::can()`.

### 16. Signed, expiring URLs and rate limits on transfer endpoints — S

Exports land on a disk and stream back, and import accepts uploads. A signed URL
with a TTL, plus a per-user throttle on export and import, closes the most
obvious abuse of a package that is otherwise carefully locked down. Security is
already a selling point here, so a gap costs more than it would elsewhere.

**Where:** `routes/`, `TransferController`, `config/dynamic-table.php`.

### 17. Session expiry and network failure handling — S  ✅ built

A 419 after an idle afternoon currently fails as an opaque error. Detect it and
say "your session expired — reload to continue"; offer a retry on network
failure instead of leaving a spinner. It applies to every fetch, so it belongs
in one place.

**Where:** the fetch wrapper in `core.js`, `resources/lang/*/table.php`.

## Integration and developer experience

### 18. Livewire, Inertia and a Blade component — M  ✅ built

The entry point today is a Blade directive, and `core.js` has a single Livewire
mention. Ship `<x-dynamic-table :table="..."/>`, a documented re-init on
`livewire:navigated` and Inertia visits, and a clean teardown that removes
listeners. This is adoption rather than features: many applications will not
evaluate a grid that does not survive their navigation layer.

**Where:** `DynamicTableServiceProvider.php`, `core.js`, `docs/installation.md`.

### 19. `php artisan dynamic-table:doctor` — M  ✅ built

Walk the registered tables and report what will hurt in production: a default
sort on an unindexed column, a searchable column with no index, `saved_views`
enabled with the migration not run, a table past `pagination.count_threshold`
with no `infinite` mode, a relation column that will be selected but never eager
loaded, a `themes` config naming a slot that does not exist.

This is the command that turns `docs/performance.md` from something people read
into something the package checks.

**Where:** `src/Commands/`, `Support/SchemaIntrospector.php`,
`Support/TableRegistry.php`.

### 20. Blade slots and render hooks — M  ✅ built

The only extension point for appearance is the theme class map. There is no way
to put a custom control in the toolbar, a banner above the table or a legend
below it without forking `table.blade.php`. A small set of named slots —
`toolbar.start`, `toolbar.end`, `above`, `below`, `empty` — rendered by both
renderers keeps people on the upgrade path.

**Where:** `table.blade.php`, `Support/TableRenderer.php`, `core.js`.

---

# P2 — Medium priority

## Data and query

### 21. Field-to-field comparison operands — S  ✅ built

Every operator in `Operator.php` compares a column to a literal. `ends_at <
starts_at` and `paid_amount < total` are data-quality questions people ask
constantly. One new operand shape (`{"field": "total"}`), allowlisted through
the same path as any other identifier.

### 22. Saved filter snippets — M

A saved view carries columns, sort, page and filters together. Sometimes only
"the Overdue condition" should be reusable, across views and across tables on
the same model. A small model plus a picker in the filter builder; reuses the
sharing already built for views.

### 23. JSON column paths — M

Nothing matches `json_column`. `data->address->city` as a column, a filter
target and a sort target. MySQL and Postgres both express it; the detection
belongs in `Support/SchemaIntrospector.php`, and the guard is that the path is
parsed and rebuilt rather than interpolated.

### 24. Reader-defined computed columns — M

The picker can add schema fields; a reader cannot express `price * quantity`. A
tiny whitelisted expression evaluator — arithmetic and concatenation only,
never raw SQL, evaluated in PHP on the formatted row — extends the picker
without touching the rule that nothing from a request reaches SQL as an
identifier.

### 25. Tree and hierarchical rows — L

`parent_id`, expand/collapse, and filter-aware ancestors so a matching child
still shows its parents. Real work in `QueryEngine`; high payoff for category,
account and org-chart tables.

### 26. NULL ordering control — S

`NULLS LAST` per column on a nullable sort. MySQL needs the `ISNULL()` trick;
worth encoding once in `QueryEngine` so nobody re-derives it.

## Columns and rendering

### 27. Column groups — M

A `Contact` header spanning `email` and `phone`. Wide tables read very
differently with two-level headers, and both renderers plus the resize and
reorder logic need to agree — a good candidate to design once, deliberately.

### 28. Masked / PII columns with a reveal — M

`->masked()` showing `•••• 4821`, revealed per record behind an ability and
logged when it is. Pairs with **15**; hiding a column outright is often too
blunt for what an audited application actually needs.

### 29. Currency and locale-aware numeric columns — S

A currency column that reads its code from another column on the same row, and
formats through the reader's locale rather than the server's.

### 30. Density toggle — S

Compact / comfortable / spacious, persisted per reader through
`Support/StateMemory.php` and expressed as one more slot in the theme map.

### 31. Distinct empty states — S  ✅ built

"No records yet" and "no rows match your filters" are different situations and
today they render the same. The second should offer **Clear filters**; the
first should be able to carry a call to action.

### 32. Skeleton loading, preserved scroll and focus — S

A skeleton in the shape of the table instead of a spinner, and keeping scroll
position and keyboard focus across a refetch. Cheap, and it is most of what
makes a grid feel fast when it is not.

## Reading

### 33. Stat tiles and mini charts — M

A `stats()` method returning KPI cards computed from the *filtered* query, above
the table, optionally with a sparkline. Reuses the aggregation path from **2**.

### 34. Master–detail — M

Let `rowDetail()` return another `DynamicTable` class — orders to line items —
instead of a Blade view. The payload contract already supports it; the work is a
depth guard and namespacing the child's state so the two tables cannot collide.

### 35. Bookmarked or pinned rows — S  ✅ built

A reader marks rows to keep at the top, or to filter to. Per user, stored beside
the saved-view state.

## Views, export and import

### 36. Scheduled views — M

A saved view, a cron expression and a recipient: an XLSX in the inbox every
Monday. `ViewEngine` and `ExportJob` both exist; this is a small model and a
command.

### 37. Named export profiles — M

"Finance export" = these columns, in this order, XLSX, this date format — saved
and reusable, like a view but for output.

### 38. Copy selection to clipboard as TSV — S

Pure client-side, no endpoint, and it is what people actually do with a grid:
select rows, paste into Excel. The highest delight-per-line ratio on this list.

### 39. Remembered import mappings and a dry run — M  ✅ built

The column mapping is redone by hand on every upload; store the last one per
table and reader in `StateMemory` and preselect it. Then show "will create 12,
update 40, reject 3" before anything commits, with the rejects downloadable as a
CSV.

### 40. Multi-sheet XLSX when `grouping` is on — S

One sheet per group value. `XlsxWriter` already writes table parts, so this is
mostly loop structure.

## Operations

### 41. Change history — M

An optional `dynamic_table_changes` log written by the shared write normaliser,
surfaced as a History panel in row detail. `RowUpdated` and
`BulkActionExecuted` already fire, so it can ship as a listener rather than as
core behaviour.

### 42. Instrumentation events — S

An event per render carrying query count, timing and payload size, for an APM or
a log. Grids are where N+1s hide; the package is in the best position to say so.

### 43. Payload caching for read-heavy tables — M

A short TTL on the rendered payload for dashboard tables that are read far more
often than written, keyed by the full `TableState` and the viewer's abilities —
that key is the whole design problem, and getting it wrong leaks rows.

## Theming

### 44. `php artisan dynamic-table:theme {name}` — S

Dump the resolved slot map as a ready-to-paste `themes` array with every slot
listed and commented. Today an author reads `Theme.php` to learn the slot names;
this makes the class map self-documenting from the CLI.

### 45. Slot-map validation — S

An unknown *feature* name throws, deliberately, because a silently ignored name
means a feature the author thinks is on. An unknown *theme slot* is silently
dropped — so `'buttons' => …` fails quietly and the author goes hunting. Same
reasoning, same treatment: throw, or at minimum warn when `app.debug` is on.

## Developer experience

### 46. `php artisan dynamic-table:list` — S

Every registered table with its model, resolved feature set, column count and
metadata-cache state. Two hours of work, and the first thing anyone runs when a
table misbehaves.

### 47. A debug panel behind `app.debug` — M

Per table: the SQL and timings, the resolved feature set, payload size, cache
hit or miss. Shown next to the table, so a regression is noticed rather than
discovered.

### 48. Shipped test helpers — M

`docs/testing.md` explains how to test your own tables; the package could hand
over the tools — `assertColumn`, `assertRowCount`, `assertFilterAllows`,
`actingAsReader`. It would shrink the package's own suite too.

### 49. A public JSON contract, and "Copy as PHP" — M

Two halves of one idea: document `TablePayload` as a stable contract so a mobile
or SPA client can consume the same table, and let a reader who has built the
perfect view — filters, columns, sort — click **Copy as PHP** and get the table
class that reproduces it. The second is a demo-stealing feature and costs almost
nothing, because all of that state is already serialised.

## Accessibility

### 50. ARIA grid semantics — M

`role="grid"`, roving tabindex, and live-region announcements for "12 rows,
filtered". Partly there already. Worth finishing and then *stating*, since the
documentation already trades on guarantees.

---

# P3 — Low priority

### 51. Pivot / crosstab mode — L
Genuinely different from a grid; probably a sibling class rather than a feature
flag on this one.

### 52. Cell comments and annotations — M
Tied to record plus column, shown as a corner marker.

### 53. An undo window for destructive bulk actions — M
A ten-second "Undo" toast backed by a delayed job, for deletes and status
changes.

### 54. Virtual scrolling — M
For 100k rows in one viewport. The `infinite` pagination mode already covers
most of this need, which is why it is here rather than higher.

### 55. More cell renderers — S
Progress, rating, sparkline, chips, avatar and duration exist. Missing and
cheap: relative time (with the absolute value in the tooltip), file size, colour
swatch, copy-to-clipboard, auto-linked email/tel/url, and a boolean toggle that
writes through `inline_edit`.

### 56. A per-table accent token — S
`protected $accent = '#7c3aed'` setting `--dynamic-table-accent` on the root.
Fits the "colour comes from CSS tokens, themes contribute layout only" rule
exactly, and lets two tables on one page differ without a new theme.

### 57. A separate slot map for print and export — S
The print view inherits screen layout classes it does not want, and accumulates
overrides in `print.blade.php` to undo them.

### 58. A theme builder page in the demo — M
Adjust the tokens, see the table change, copy the config array out.

### 59. More locales, and a parity check in CI — S
de, fr, es, tr beside en/ar/he/ru — and a test that fails when the language
directories drift out of key parity. The check is cheap and prevents a bug that
recurs.

### 60. Scaffold from a model — S
`make:dynamic-table UsersTable --model=User --features=export,saved_views`,
writing the detected columns out as a commented starting point.

### 61. Import from a URL or Google Sheet — M
The reader pastes a link instead of uploading a file.

### 62. Reduced motion and high contrast — S
Honour `prefers-reduced-motion` and `prefers-contrast` in the package
stylesheet.

---

# Strategic

### 63. `DynamicForm` — the create and edit form from the same metadata — L

`MetadataEngine` already knows types, casts, enums, relations, nullability and
validation rules. `inline_create` already proves the write path. The same class
that renders the table could render its create and edit form — and the package
would offer zero-configuration **CRUD** rather than zero-configuration read.

It is the largest thing that could be built on what already exists here, and the
one that would change what the package is rather than what it does.

---

## If only five get built

**1** (conditional formatting), **2** (totals and group aggregates), **15**
(column-level authorization), **19** (`doctor`) and **7** (queued bulk actions).

The first two are what reviewers notice in a screenshot; the third and fourth
are what makes the package safe to standardise on; the fifth is the bug report
that is coming anyway.

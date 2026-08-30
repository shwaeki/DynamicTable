# Changelog

All notable changes to Laravel DynamicTable are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Bulk edit** (`bulk_edit`). Set the same columns on every selected record.
  Validated once, then applied record by record in chunks of 500, so policies
  apply, observers fire, and a record the viewer may not update is skipped
  rather than failing the operation. Only ticked fields are sent.
- **Inline create** (`create`). A blank row at the top of the table, using the
  same controls, metadata and rules as inline editing, saved as one request —
  with `newRecordDefaults()` for the columns the row does not ask for.
- **Toolbar actions** (`toolbar_actions`). Your own buttons beside Filters and
  Columns, as links or server handlers, with optional inputs collected in a
  dialog and validated on the server.
- **Row detail expander** (`row_detail`). A chevron per row opens a panel
  rendered by `rowDetail()`, fetched on demand and cached until the rows
  change. The record is re-fetched through the table's own base query and
  checked against the `view` ability.
- **Sticky columns** (`sticky_columns`). `$stickyColumns` freezes leading
  columns and `$stickyActions` freezes the row buttons at the opposite edge.
  Offsets are measured in the browser and written as logical insets, so the
  correct edge freezes in RTL.
- **Faceted counts** (`facets`). Filter values carry how many rows they would
  keep. The current search and filters apply except any condition on that same
  column, so choosing one value does not zero the others. One grouped query,
  opt-in per column, run only when a dropdown opens.
- **Infinite scrolling** (`$pagination = 'infinite'`). Pages are appended
  instead of replaced, driven by an IntersectionObserver on a sentinel, and no
  `COUNT(*)` runs.
- **Two ready-to-use themes that need no CSS framework**: `minimal` (airy,
  rules between rows) and `bordered` (dense, ruled like a spreadsheet).
- Nine new demo examples covering all of the above, translated into Arabic,
  Hebrew and Russian, and a complete [All features](docs/features.md)
  reference page in the documentation.

### Fixed

- **The sticky header did not stick.** `overflow-x: auto` on the scroller makes
  it a scroll container on *both* axes, so a header pinned to its top never
  moved while the page scrolled past it. The table now has a height of its own
  (`$maxHeight`, or `dynamic-table.table.max_height`, default `70vh`), the
  scroller owns the scrolling, and the header is opaque rather than inheriting
  a transparent background. `'none'` restores the old page-flow behaviour.
  Paging scrolls the table back to its own top rather than moving the page, and
  infinite scrolling observes whichever element actually scrolls.
- **Table chrome overflowed on a phone.** The toolbar and footer now stack, and
  the page list scrolls on its own line instead of widening the layout. In the
  demo, the header wraps and compacts, the sidebar drawer is in flow rather
  than pinned to a guessed header height, and wide Markdown tables scroll.
- **Columns rendering HTML showed their markup as text.** The `raw` column flag
  was never serialised, so both renderers fell through to the escaped branch. It
  is now part of the column payload and checked ahead of every type branch, and
  a render closure declaring an `Htmlable` return type is treated as raw
  without needing the flag.

- **Unreadable tables in dark mode.** The bundled Tailwind theme carried `dark:`
  colour variants, which follow the operating system under Tailwind's default
  media strategy. In a light-only application on a dark-mode machine the table
  rendered light text on a white card. Colour now comes from the package's own
  CSS tokens for every theme, so Bootstrap, Tailwind and custom themes are all
  legible in both schemes — and a scheme can be forced per table.
- **Panels could open twice**, stacking two identical filter or column dialogs.
  There were two causes. Every feature module imported `./core.js` without the
  version query, so the browser fetched a *second* copy of the core at a
  different URL and evaluated it again — giving the page two runtimes bound to
  the same DOM, each handling every click. Shared helpers now live in `dom.js`
  and nothing imports `core.js`; the registry and the boot flag are global; and
  a table shows at most one dialog and one menu, with the open guard held on the
  element rather than only on the object.
- **Assets could be cached stale for a year.** Modules imported by other modules
  arrived without the version query but were still served
  `Cache-Control: immutable, max-age=31536000`, so a deploy left old modules
  cached beside a fresh core — and a shipped fix might never reach a browser.
  The version is now part of the asset *path*, which relative imports inherit,
  so every module on a page comes from one versioned directory. The version is
  derived from every asset's timestamp, not just the core's.
- `dom.js` was missing from the served-asset allowlist, so it 404'd.
- The demo highlighted its Blade snippet with a `blade` grammar highlight.js
  does not ship, which logged a warning on every example page.
- The filter builder's field tree no longer walks back into a model already in
  the current path, which produced redundant groups such as
  `invoice.order` on an orders table.

- A table added since the discovery cache was written rendered through the
  directive but returned 404 from its own endpoint. The registry now rescans
  once before failing, so adding a class never needs a cache clear.

### Added

- **Column header menu**, in the style of Dynamics 365 grids: sort either way
  (labelled by type), group by the column, filter on it, set its width, move it
  one position, or hide it. It appears only when at least one of those actions
  is possible, and offers only the items the enabled features support.
- **Grouping** is now implemented rather than only flagged. It compiles to a
  leading `ORDER BY` and the browser inserts heading rows where the value
  changes — no extra query, and nothing loaded into PHP to be grouped.
- **Responsive modes**: `collapse` (the new default) hides the columns that do
  not fit and reveals them in an expandable child row, as DataTables Responsive
  and PowerGrid do; `cards` stacks rows below a breakpoint; `scroll` is the old
  behaviour; `none` switches it off. Column `priority` and `$responsiveFixed`
  decide what collapses first. Columns are measured, not guessed at.
- **The view picker is now the heading**, as in Dynamics: the current view's
  name with a chevron, opening a searchable list with a tick on the current view
  and a marker on the default, then reset / save / manage.
- **Row actions**: `RowAction::make()` for buttons on every row, either a link
  (`->url()`) or a server-side handler (`->handle()`), with per-record
  `visible()` and authorisation. The server re-checks both against the record
  before running anything. Behind the `row_actions` feature.
- A render closure returning an `Htmlable` (`HtmlString`, a Blade view) is
  treated as safe HTML without needing the `raw` flag — so putting a badge, a
  thumbnail or a small button in a cell no longer means opting out of escaping
  for the whole column.
- **Views can be shared with named people**, one or many, from Manage views.
  Sharing grants read access only — renaming, editing and deleting stay with the
  owner. Each view shows an icon for where it came from (yours, shared with you,
  shared by you, system, built-in) and names the owner when it is not yours.
  Backed by an indexed `dynamic_table_view_shares` table; the people search is
  reachable only by someone who can already share that view.
- **Panels can be shown as an offcanvas drawer** instead of a centred modal,
  application-wide with `config('dynamic-table.panels')` or per table with
  `$panels`. `end`/`start` follow the reading direction, so a drawer opens from
  the right in English and the left in Arabic. Both share the same markup, focus
  trap and Escape handling.
- **Users can choose their own default view.** "Manage views" lists every view
  with a star to set or clear the default, plus rename, share and delete. A
  user's default and the system default are stored independently.
- `$scheme` on a table and `dynamic-table.scheme` in the config: `"light"`,
  `"dark"`, or `null` to follow the viewer's system. Rendered as
  `data-dt-scheme`, so it can also be changed at runtime.
- **Pagination strategy**: `$pagination` of `'length_aware'`, `'simple'` or
  `'auto'`. `auto` uses the database's own row estimate — instant, unlike
  `COUNT(*)` — and stops counting past
  `config('dynamic-table.pagination.count_threshold')`, showing previous/next
  instead. This is what makes a ten-million-row table page instantly.
  An uncounted table still reports its approximate size
  (`Showing 1–25 of about 10,000,000`) while nothing narrows the result set, and
  falls back to a plain range once a filter makes that estimate meaningless.
  Row counts in the summary are now locale-formatted.
- **Large-dataset examples** in the demo at 100,000, 1,000,000 and 10,000,000
  rows over a narrow indexed table, seeded on demand with
  `php artisan dynamic-table:scale 100k|1m|10m`.
- `header_menu` is a proper feature flag, on by default and switched off with
  `'-header_menu'`.
- `responsive` gained an application-wide switch:
  `config('dynamic-table.responsive.enabled')` and `.mode`, with `$responsive`
  now defaulting to `null` so a table follows the config.
- Changing page scrolls the top of the table back into view, but only when it
  has actually scrolled out of sight — paging a short, fully visible table does
  not move the page. Honours `prefers-reduced-motion`, is tunable with
  `.dt { scroll-margin-top: … }` for fixed headers, and can be switched off with
  `config('dynamic-table.pagination.scroll_on_page')`.
- Column option `priority`, and `data-label` on every cell.
- **The demo now hosts the full documentation** at `/dynamic-table/docs`,
  rendered from the package's own `docs/*.md` at request time — not a copy.
  Links between pages are rewritten to real URLs, headings get anchors, each
  page has an on-page contents and previous/next in reading order, and the whole
  body is searchable from the sidebar. Examples and documentation share one
  responsive chrome with a section switcher.
- The demo has a Light / Dark / Auto switch that drives the demo chrome,
  Bootstrap's own components and every table from one control, and is fully
  translated into Arabic, Hebrew and Russian — sidebar, categories, titles,
  descriptions, the 101 "what to look for" notes and dates included.

### Changed

- `declare(strict_types=1)` has been removed from every file, and Pint no longer
  adds it.


## [1.0.0] — 2026-08-29

First public release.

### Core

- `DynamicTable` base class: a table is one PHP class, and `$model` is the only
  required property
- `@dynamicTable(UsersTable::class)` Blade directive — no Livewire tag, no
  custom Blade component, no route-as-table
- `php artisan make:dynamic-table`, `dynamic-table:install`,
  `dynamic-table:clear`
- Table registry with deterministic keys and duplicate detection

### Metadata engine

- Column discovery from the schema, casts, `$appends` and relationships
- Type detection: boolean, integer, decimal, date, datetime, time, enum, json,
  uuid, email, url, image
- Backed-enum options read from the cast, with `label()` support
- Foreign keys automatically replaced by their relationship's label column
- Sensitive columns excluded by default
- Cached, with `dynamic-table:clear` to invalidate

### Querying

- Server-side search, per-column search, sorting, pagination
- Advanced filter builder with nested AND/OR groups, 30+ typed operators and
  relative date ranges
- Relationship columns, filters and search via `whereHas` — no joins, no
  duplicated rows
- Relationship sorting via correlated subquery
- Soft-delete modes (without / with / only)
- `query()` and `$scopes` as a hard, un-escapable boundary

### Views

- User views, system views and developer presets
- Default-view precedence: user → system → preset → table
- Versioned declarative JSON configuration; stale fields degrade gracefully
- Optional URL state synchronisation

### Editing and actions

- Inline editing with typed controls, per-row validation and authorisation
- Spreadsheet mode: cell cursor, range selection, copy/paste, fill-down, undo,
  batched save — dependency-free, with a documented adapter seam
- Bulk actions with confirmation, extra input fields and per-ability
  authorisation
- Selection modelled as include/exclude so "select all" never ships ids

### Export and import

- CSV built in; XLSX through openspout or PhpSpreadsheet when present
- Export scopes: current page, current view, all records, selected
- Import with automatic mapping, preview, chunked transactional processing,
  per-row errors and a downloadable error report
- Queued transfers past a configurable threshold with poll-based progress
- CSV formula-injection neutralisation and a UTF-8 BOM

### Presentation

- Bootstrap 5 and Tailwind themes; a custom theme is one array
- Complete Arabic, Hebrew, English and Russian translations
- RTL built on logical CSS properties, not a `direction` flip
- Responsive layout, keyboard navigation, ARIA, focus management

### Engineering

- 120 tests across unit, feature, security and performance groups
- PHPStan (larastan) level 5, Laravel Pint
- Query-count and memory budgets asserted in CI

[Unreleased]: https://github.com/shwaeki/DynamicTable/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v1.0.0

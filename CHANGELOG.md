# Changelog

All notable changes to Laravel DynamicTable are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.1] — 2026-08-30

### Fixed

- Action icons are now normalised to safe HTML once, on `->icon()`, instead of
  each renderer deciding. Every place that draws one — the server-rendered
  table, the browser repaint, toolbar buttons, menu items — inserts it, so an
  icon-font element can no longer come out as text on any path. `->icon()` also
  accepts an `Htmlable` (`new HtmlString('<i class="far fa-edit"></i>')`), which
  is treated as markup outright.

## [1.2.0] — 2026-08-30

### Fixed

- Action icons written as icon-font markup — `->icon('<i class="far fa-edit"></i>')`
  — were printed as escaped text. Icons that look like markup are now rendered as
  markup (row actions, toolbar actions and menu items, server-rendered and in the
  browser); anything else is still escaped.
- A render closure returning an `Htmlable` had its markup escaped in the browser
  unless the closure declared the return type. The rendered cell now carries that
  decision per row, so an untyped closure works too, and exports keep stripping the
  tags out.

### Added

- Virtual columns: a declared column with a `render` closure but no matching model
  attribute — a thumbnail built from the record, an actions strip — is kept instead
  of silently dropped. It is computed, so it is never sorted, filtered or searched.

## [1.1.0] — 2026-08-30

### Added

- Laravel 10 support. `Schema::getColumns()` and `Schema::getIndexes()` are
  Laravel 11 additions, so the new `Support\SchemaIntrospector` uses them when
  they exist and otherwise runs the equivalent driver queries (SQLite, MySQL,
  MariaDB, PostgreSQL, SQL Server) itself. `doctrine/dbal` is still never
  required. The test matrix now covers Laravel 10 on PHP 8.2 and 8.3.

## [1.0.0] — 2026-08-30

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

### Columns and headers

- Column header menu in the style of Dynamics 365 grids: sort either way, group
  by the column, filter on it, set its width, hide it
- Column resizing that grows the table rather than taking width from a
  neighbour, and honours a width declared on the column
- Column picker that can add any column the table can reach, not only the
  declared ones
- Sticky columns (`$stickyColumns`) that survive horizontal scrolling
- Built-in cell renderers: `progress`, `rating`, `sparkline`, `chips`, `avatar`
  and `duration`, each one word in the column definition, server rendered as
  inline SVG with no chart library
- Summary row: `'summary' => 'sum'` (or `avg`, `min`, `max`, `count`) puts an
  aggregate under a column, over the whole filtered result, in one query

### Views

- User views, system views and developer presets
- Default-view precedence: user → system → preset → table
- Versioned declarative JSON configuration; stale fields degrade gracefully
- Views can be shared with named people, and a user can pick their own default
- Optional URL state synchronisation

### State and presentation modes

- Table parameters (`$params`): your own controls — a date range, a branch
  picker, a report id — feeding `query()` on every request, read from the page
  request on first paint and carried through exports and prints
- Remembered state (`remember_state`): the table reopens the way it was left
- Responsive modes: `collapse` (the default) hides the columns that do not fit
  and reveals them per row; `cards` reflows the table below a breakpoint
- Pagination strategies: `length_aware`, `simple` or `infinite`
- Print view built for paper: repeating header, rows that never split across
  sheets, and the search, filters and sort that produced them
- Panels as a centred modal or an offcanvas drawer, resolved against the
  reading direction
- Faceted filter counts: filter values carry how many rows they would leave
- Empty states that tell "no records at all" apart from "nothing matches"

### Editing and actions

- Inline editing with typed controls, per-row validation and authorisation
- Inline create: a blank row at the top of the table, validated and authorised
  the same way an edit is
- Bulk edit: set the same columns on every selected record
- Bulk actions with confirmation, extra input fields and per-ability
  authorisation
- Row actions (`RowAction::make()`) and toolbar actions of your own beside
  Filters and Views
- Row detail expander: a chevron per row opens a panel rendered by your view
- Selection modelled as include/exclude so "select all" never ships ids

### Export and import

- CSV built in; XLSX through openspout or PhpSpreadsheet when present
- Export scopes: current page, current view, all records, selected
- Import with automatic mapping, preview, chunked transactional processing,
  per-row errors and a downloadable error report
- Queued transfers past a configurable threshold with poll-based progress
- CSV formula-injection neutralisation and a UTF-8 BOM

### Presentation

- Bootstrap 5, Tailwind, minimal and bordered themes; a custom theme is one
  array, and themes live in a publishable `config/dynamic-table-themes.php`
- Light, dark and automatic colour schemes driven by one control
- Complete Arabic, Hebrew, English and Russian translations
- RTL built on logical CSS properties, not a `direction` flip
- Responsive layout, keyboard navigation, ARIA, focus management

### Engineering

- 170 tests across unit, feature, security and performance groups
- PHPStan (larastan) level 5, Laravel Pint
- Query-count and memory budgets asserted in CI

[1.1.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v1.1.0
[1.0.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v1.0.0

# Changelog

All notable changes to Laravel DynamicTable are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Three themes, and only three: `custom`, `bootstrap`, `tailwind`.** The list
  was six names deep and an author had to work out which of them their
  application wanted; the answer was always "the framework I already load, or
  none". `minimal`, `bordered`, `none` and the `bootstrap5` alias are gone, and
  any name the package does not recognise now resolves to `custom` rather than
  to Tailwind — so an application that named one of the removed themes keeps a
  finished-looking table instead of an unstyled one.

- **`custom` is a finished theme, not an empty slate.** It used to hand back
  the structural classes and nothing else. It is now the package's own look —
  card, quiet uppercase headers, comfortable rows, accent buttons, focus rings,
  rounded menus — painted from the same tokens as everything else, so it needs
  no Bootstrap, no Tailwind and no build step, and reads correctly in light and
  dark. Its shape is tunable from CSS: `--dynamic-table-custom-card-radius` and
  `--dynamic-table-custom-gutter` on `.dynamic-table-custom`.

- **A theme in config starts from the built-in of the same name.** Changing one
  slot of Bootstrap is now `'themes' => ['bootstrap' => ['badge' => '…']]`, and
  a name of your own starts from `custom`. This replaces `extends`, which is no
  longer read (the key is ignored if a config still carries it).

- **Tables are light by default.** `config('dynamic-table.scheme')` ships as
  `'light'` instead of `null`, so the same page no longer renders light or dark
  depending on whose operating system opened it. Set it back to `null` to
  follow `prefers-color-scheme`, or to `'dark'` for dark everywhere; `$scheme`
  still overrides per table.

- **The default theme is `bootstrap`** (it was `tailwind`).

### Removed

- **`Theme::register()`.** A theme is the `themes` key of
  `config/dynamic-table.php` — one file, no service provider. A theme that
  genuinely has to be computed can still set the config value at runtime.

## [2.2.0] — 2026-09-01

### Changed

- **The methods you override no longer declare return types either.** PHP will
  not let a child method drop a type its parent declared, so `DynamicTable`
  declares none — both spellings are yours to pick:

  ```php
  protected function columns() {}
  protected function columns(): array {}
  ```

  Every signature carries an `@return` tag instead, so static analysis and IDE
  completion are unaffected. Same reasoning as the untyped properties in 2.1.0.

## [2.1.0] — 2026-09-01

### Changed

- **Table properties are no longer typed in the base class.** PHP requires a
  redeclared property to repeat its parent's type exactly, so a declaration in
  `DynamicTable` forced one spelling on every table in every application.
  Nothing is declared there any more, and both spellings work:

  ```php
  protected $model = User::class;
  protected string $model = User::class;
  ```

  A property you never mention keeps its default, and the documented defaults
  now live in one block at the top of the class alongside `@property` tags for
  static analysis and IDE completion.

### Fixed

- **`tinyint(1)` columns are detected as booleans again.** MySQL reports
  `tinyint` as the type name and keeps the width in the full type, so the
  boolean convention never matched and a column like `active` was typed as an
  integer — numeric alignment, numeric filters. The full type is read as well
  now, and an uncast integer named like a flag (`active`, `enabled`,
  `published`, …) is treated as one.

## [2.0.0] — 2026-09-01

### Changed — breaking

- **The CSS prefix is now `dynamic-table`, not `dt`.** `dt-` is one of the most
  contested two-letter prefixes on the web — DataTables, half the admin
  templates on ThemeForest and a good deal of hand-written application CSS all
  claim it — and a stylesheet meant to stay out of the host application's way
  cannot start by taking a name that common. Every emitted name changed the
  same way, with no exceptions and no aliases:

  | Before | After |
  | --- | --- |
  | `.dt` | `.dynamic-table` |
  | `.dt-row`, `.dt-cell`, `.dt-th`, … | `.dynamic-table-row`, `.dynamic-table-cell`, `.dynamic-table-th`, … |
  | `--dt-accent`, `--dt-surface`, … | `--dynamic-table-accent`, `--dynamic-table-surface`, … |
  | `data-dt-scheme`, `data-dt-row`, … | `data-dynamic-table-scheme`, `data-dynamic-table-row`, … |

  The Blade directives, the config keys, the `dynamic-table:*` DOM events and
  every PHP signature are untouched. See [UPGRADE.md](UPGRADE.md) for the
  one-line migration.

- **The `soft_deletes` feature is gone.** Eloquent already says all three
  things — the default global scope, `withTrashed()` and `onlyTrashed()` — so
  the feature was a flag wrapping a builder method, plus a `trashed` state slot
  that nothing in the UI ever set. Say it in `query()` instead:

  ```php
  public function query(Builder $query): Builder
  {
      return $query->withTrashed();
  }
  ```

  Nothing is lost in the visible result: striking a trashed row through now
  happens whenever the *model* uses `SoftDeletes`, rather than only when the
  feature was on — so a table that reaches those rows through its own `query()`
  gets the styling it never used to. See [UPGRADE.md](UPGRADE.md).

- **Five features renamed, with no aliases.** A name should read as the thing
  it switches on, and these did not:

  | Was | Now | Why |
  | --- | --- | --- |
  | `column_reordering` | `column_reorder` | `picker` is a noun; `-ing` made the trio read as three unrelated things |
  | `column_resizing` | `column_resize` | same |
  | `facets` | `filter_counts` | search jargon for "counts beside filter values" |
  | `views` | `saved_views` | collided with Blade views in every Laravel codebase |
  | `create` | `inline_create` | "create" what? It is a blank row, and it pairs with `inline_edit` |

  `$facets` follows its feature and is now `$filterCounts`, as does
  `facetKeys()` → `filterCountKeys()`.

  **An unknown feature name is now an error** rather than being ignored. That
  is what makes a clean break safe: a stale `'column_reordering'` would
  otherwise have silently meant "no reordering" — the failure mode the rename
  would have caused most often. The exception names the offending table and
  lists every valid feature.

- **The three column features no longer imply one another.** `column_reorder`
  and `column_resize` each pulled in `column_picker`, so asking for draggable
  order — or merely draggable widths — silently handed the reader Add column
  and Remove as well. That is why the two looked like the same feature. They
  are now independent: the panel is shared chrome, and each feature puts only
  its own controls in it. `column_resize` opens no panel at all; its handles
  are in the header. `saved_views` still implies the picker, which is a real
  data dependency — a view stores a column selection, so something has to be
  allowed to restore it.

- **Exports and the import template default to XLSX**, not CSV, wherever a
  spreadsheet library is installed. A spreadsheet is what people do with an
  export, and the file now arrives filterable and readable rather than as raw
  commas. `config('dynamic-table.excel.default_format')` set to `'csv'` puts CSV
  back in front; with no library installed CSV is the only option either way.

### Changed

- **The filter builder and column picker were redesigned.** Nesting in the
  filter builder was signalled by two background washes — 4%% and 7%% of the
  accent — which were indistinguishable by the second level and nearly
  invisible on a dark scheme. Each nested group now carries a rule on its
  inline-start edge with its And/Or sitting on it, so the boolean tree is read
  structurally. Written with logical properties, so it grows from the right in
  Arabic and Hebrew.

  Each condition is now drawn as one object rather than as loose rows of
  controls — stacked bare, three conditions were six rows with nothing to say
  where one ended and the next began, worst in a drawer where each takes two
  lines. A nested group's remove moved from trailing its Add buttons, where it
  read as "undo the last thing I added", to the head beside the And/Or it
  belongs to. Every empty group now says so instead of showing two orphaned
  buttons.

  The column picker gained a count on its heading, turned Add column and Reset
  from bare links into real controls, moved the relation hint into a chip so it
  stops floating between the name and the edge, and made the remove button
  recede until its row is hovered — a column of red crosses reads as a list of
  problems.

- **Offcanvas panels are laid out as drawers, not as a modal docked to an
  edge.** Lists that capped their own height for a dialog let the drawer body
  scroll instead, so a full-height panel no longer shows a short list with a
  second scrollbar and dead space beneath it; and a filter condition stacks its
  three controls rather than squeezing them into 30rem.

### Added

- **An exported XLSX is a real Excel table.** Not a grid with a bold first row
  — the thing "Format as Table" makes, with the Table Design ribbon, structured
  references (`=SUM(Export[Total])`) and a name.
  `config('dynamic-table.excel.style')` takes three kinds of value:

  | Value | Result |
  | --- | --- |
  | `'TableStyleMedium2'` (default) | a real Excel table in that style |
  | `true` | a styled range: dark headings, banded rows, a filter across every column |
  | `false` | a bare grid of values |

  Names are Excel's own built-ins — `TableStyleLight1`–`21`,
  `TableStyleMedium1`–`28`, `TableStyleDark1`–`11`. An unrecognised one
  **throws**, on the same reasoning as an unknown feature name: a style Excel
  drops on the floor is a style the author thinks is applied.

  Both drivers produce the same file. openspout has no notion of a table, so
  the four OOXML parts one needs are added to the finished archive; the
  relationship is merged into whatever the writer already put in the sheet's
  rels rather than replacing it. PhpSpreadsheet builds one natively.

- **An XLSX carries the table's reading direction.** An Arabic or Hebrew table
  exports right-to-left, an LTR one left-to-right — Excel cannot work that out
  from the content. Whatever the style mode, the file also freezes its heading
  row, sizes columns to their content (one integer per column as the rows
  stream, so a million-row export measures itself for the price of the
  headings) and names the sheet tab after the table rather than `Sheet1`.

- **`config('dynamic-table.excel.adapter')` names the driver** — `auto`,
  `openspout`, `phpspreadsheet` or `csv` — for an application that would rather
  keep a single spreadsheet library, usually because Laravel Excel already
  brought PhpSpreadsheet in.

- **The import panel is the three steps it actually is**: choose a file, match
  the columns, check and import. All three show from the start, with the ones
  not yet available held back rather than hidden, so the panel says up front
  what it will ask for. The rail's line is the one the filter panel draws down
  its nested groups, on purpose.

- **Required columns are enforced before anything is written.** A column that
  is `NOT NULL` in the schema has to be mapped: the panel marks the rows that
  fill one, names any still missing, and keeps **Start import** disabled until
  there are none. The server checks the same thing, so a code-driven import
  cannot skip it. The primary key is exempt, and so is update mode, which only
  writes the columns it is given.

- **The export panel says what the file will contain** before you click: the
  row count or range, the format, and the columns — restated from the two
  selects rather than left to be worked out. It only claims a number where
  there is an honest one, so "every record" with filters on says so in words
  rather than showing the filtered total.

- A **`param-filters` example** in the demo: a filter bar in the host
  application's own markup driving the table with `data-dynamic-table-param`
  and `data-dynamic-table-table`, covering all four `$paramFilters` shapes and
  the `query()` scope they narrow.

- **`RowAction::class()` and `->withLabel()`** (and `ToolbarAction::class()`):
  a row action can wear the application's own button classes and show its label
  beside the icon. An action that names classes also gets
  `dynamic-table-row-action-custom`, and the package's own button styling steps aside for
  it — only the layout is kept, so a framework `.btn` is never fought over.
- `excel.adapter` is now read. It had been documented since 1.0.0 and never
  consulted: setting it to `'csv'` now genuinely declines XLSX, even on a
  machine where PhpSpreadsheet happens to be installed as somebody else's
  dependency, and the export dialog stops offering the format.
- **Import errors are shown, not just counted.** A failed import said "3 failed"
  and stopped there; the per-row reasons the server already returns are now
  listed under the summary, so a rejected file can be corrected without going
  to the log.
- A failed load now offers **Retry** on its alert, rather than leaving the
  reader to find the browser's reload button.
- A bulk action shows a **"Working…"** state while it runs. It can cover every
  row that matches the filters, and a silent table reads as a table that
  ignored the click.
- The column picker's two panes are now titled ("Visible columns" / "Available
  columns"), and the filter builder says "No filters applied." when it is empty
  instead of showing two bare buttons.
- **XLSX is now covered by the test suite in both directions.** `XlsxWriter` and
  `XlsxReader` were shipped code with no test of their own: the suite now
  exports a workbook and reads it back through the reader, imports one through
  the same mapping a CSV goes through, and builds an XLSX template.
  `openspout/openspout` is in `require-dev` for it — still only a suggestion
  for applications.
- An XLSX upload to an application with no spreadsheet library now answers
  **422 with the two package names to install**. The upload rules accept
  `xlsx`/`xls` regardless of what can read them, so the file used to get as far
  as the parser before failing.
- **The import error report is downloadable.** It was written on every failed
  import and returned as `report`, but no route served it and nothing could
  reach it — the dialog now offers it as a button. Four gates gate it: the
  table resolves from the registry, `import` is enabled on it, the viewer may
  import, and the key carries an HMAC signed with that table's key, so a report
  can only be fetched back through the table that produced it.
- **`relations`, a feature you can switch off.** On by default, so nothing
  changes: the filter builder and the column picker offer the fields of the
  model's singular relations as well as its own. `'-relations'` stops all three
  reach-through paths at once — the field catalogue, a column the picker would
  add on demand, and a filter on a relation path — for a table that should keep
  the reader to the model in front of them. A relation column the table itself
  declares is unaffected; this governs what the reader may reach for, not what
  you may show.

### Fixed

- **An export could not be imported back.** The file holds what the reader saw
  — `,812.43`, `13 Feb 2026 09:32`, `Yes` — and the importer read it as if it
  were raw column values, so a re-import of an untouched export failed on every
  money column with "The total must be a number". Numbers now shed a currency
  symbol, a `%` and thousands separators; booleans accept the translated
  Yes/No; and the CSV-safety apostrophe is removed again, so a phone number
  does not come back as `'+44 20 2120`. Matching an enum by its label already
  worked this way — the rest now agrees with it. A `bytes` column deliberately
  does not reverse, and a comma is only dropped where `number_format` would
  have written one, so `1,5` from a European spreadsheet is rejected rather
  than imported as fifteen.

- **A `dd/mm/yyyy` column imported with the day and month swapped.** Dates fell
  through to `Carbon::parse()`, which reads `03/02/2026` as the third of
  February and knows nothing of `13 فبراير 2026`. They are now parsed with the
  column's own `format` pattern in the current locale, with the leading `!`
  that zeroes what the pattern does not mention — a date-only column was
  otherwise picking up the clock time of the import.

- **"Create and update" reported every row as a duplicate.** The match-by
  select started empty, so it displayed a field it was not sending; the server
  fell back to the primary key, which the file does not carry, found nothing
  every time and inserted a duplicate of every row. The panel now defaults to a
  field the file supplies, and both it and the server refuse a match column the
  mapping does not fill.

- **A duplicate key reported the whole failed statement** — every binding and
  the absolute path to the database file — into the failed-rows list and the
  downloadable error report. It now reads "A record with this Reference already
  exists. Choose 'Create and update' to update it instead", against the column
  that actually clashed. Every other database failure reports the driver's own
  message without the statement.

- **"The request could not be completed."** was every refusal in the transfer
  routes, because a message-less `abort()` renders as JSON with an empty
  `message` and the client falls back to a generic string. All 24 aborts across
  the transfer, table, view, action and asset controllers and `ViewEngine` now
  carry a translated reason — the upload has already been used, a column is
  unmapped, the format cannot be written, the viewer may not import. The
  analyze step also threw the server's message away and substituted the generic
  one.

- **Column search kept its inputs under columns the responsive collapse had
  hidden.** A column is three cells, not two, and the search cell was not in
  the set being hidden — so the row was one cell wider than the header it lines
  up with, and every cell still claimed the width of an input, which meant the
  table could never shrink and the collapse went on hiding columns that were
  already hidden. A column with a live search is now pinned for as long as the
  search lasts, rather than collapsing and taking away the only box that can
  clear it.

- **"Showing 1–10 of 10tal".** `:to` matched the front of `:total`, and the
  browser's own translator replaced tokens in the order they were given.
  Laravel's `make_replacements` sorts by length for this reason, which is why
  the server-rendered first page was always right and every page after it was
  wrong.

- Operator names in the filter builder and the column header menu were drawn
  from the raw enum value — "not contains" — in every language: the boot payload
  carried `dynamic-table::table` but not `dynamic-table::operators`, so the
  browser never had the translations that were sitting in the language files.
- Badge tones no longer draw a border.
- Three em dashes in source comments were double-encoded by a bad patch in
  1.3.0.
- The pagination arrows announced themselves to a screen reader as "‹" and "›".
  They now carry the `previous` and `next` translations, which had been sitting
  unused in every language file.
- The responsive expander kept the accessible name "Show details" once the row
  was open; it now says "Hide details", which was likewise already translated.
- `dist.zip` — a stale archive of the package from an old commit — was
  committed at the repository root and therefore shipped inside every install.
  Removed, and ignored.
- **Grouping was drawn only in the browser.** A remembered state, a URL or a
  saved view can all arrive with a group already chosen, and the server-rendered
  first paint showed the rows correctly ordered but with no group headers until
  something else caused a refresh. The Blade template now draws them too.
- The value control in a filter condition had no accessible name, though the
  field and operator selects beside it did. So did the filter count beside the
  toolbar button, which was a bare number.
- The inline-edit saving and saved states were colour alone; they now carry a
  title as well.
- A URL cell truncated to `…` in the browser and to `...` on the server, so a
  long link changed shape between the first paint and the next fetch.
- `progress` returned the export's path on the application's own disk to the
  browser, which has a download URL instead and no use for it.
- **The page range was written over a column's total.** The summary-row cells
  and the footer's "Showing 11–20 of 100" line both answered
  `[data-dt-summary]`, and an attribute selector matches on the name alone — so
  `renderSummary()` found the first `<tfoot>` cell instead of the footer. On
  every refresh of a table that had both a summary column and pagination, the
  first summary cell was overwritten with the page range, and the range itself
  never updated. The footer is now `data-dynamic-table-range`.
- **A summary column cost one query per eager-loaded relation.** The aggregate
  query inherited the page's eager loads and re-ran every one of them to fetch
  a single row whose relations it never reads. One summary column on a table
  with two relation columns went from 6 queries to 8; it is now 6.
- The error report was written with `mkdir()` beside the application code
  rather than to the transfer disk, so a queued import on a worker that does
  not share a filesystem with the web process left a file the downloader could
  never see. It goes through the configured disk, as an export does.
- **The word "null" appeared at the top of the column picker.** `el()` drops a
  child that came out `null`, but `replaceChildren()` is native DOM and
  stringifies whatever it is handed, so the `column_reordering ? … : null`
  argument rendered as text on every table without that feature. Added a
  `mount()` helper that filters, so the shape cannot recur.
- **Themed buttons lost their text colour.** `.dt :where(button) { color:
  inherit }` was meant to undo the browser's own dark `buttontext`, but it
  matched the specificity of a single theme class and loaded after the page's
  own CSS — so it beat `.btn-primary`, `.demo-btn-primary` and anything else a
  theme said about its buttons, leaving dark text on a dark primary. The reset
  now carries zero specificity, so a theme with an opinion wins.
- A table with `column_picker` but `'-filters'` could not open "Add column":
  the field catalogue both panels read required the filters feature. Either one
  now serves it.
- **A table with `column_picker` but not `column_reordering` could still be
  reordered.** Removing a column and adding it back appended it to the end, so
  remove-then-re-add moved a column on a table whose whole point was a fixed
  order. A re-added column now returns to the position the table declares for
  it; one the table never declared has no declared place and still lands last.
- **A soft-deleted row was struck through only after the first repaint.**
  `data-trashed` was emitted by the browser renderer but not by the Blade
  template, so the server-rendered first paint showed it as an ordinary row.
- **A multi-column sort did not survive a reload with `url_state` on.** The
  browser writes it to the query string as one comma-separated value
  (`-total,reference`), and the server read the whole string as a single field
  name — which matched no column, so the sort was dropped and the table came
  back on its default order. Two- and three-level sorts now round-trip.

### Removed

- **Every trace of the abandoned spreadsheet-editing mode**: its stylesheet
  rules (`.dt-sheet*`), the `/spreadsheet` example the demo README linked to
  but which never existed, and the `spreadsheet.js` module the demo's asset
  test expected the package to serve.
- The stylesheet rule for a faceted count element the filter builder does not
  emit (`.dt-facet-count`) — the counts go into the option's own text.
- Ten translation keys nothing ever drew, in all four languages: `add`, `all`,
  `none`, `filter.where`, `filter.search_fields`, `views.unsaved`,
  `export.download`, `inline.discard`, `inline.save_all` and `print.action`.
  The rest of the unused ones were wired up instead.
- `source_url` from the package config — only the demo ever read it, and it now
  lives in the demo's own config file.
- The demo's unused Vite scaffolding (`package.json`, `vite.config.js`,
  `resources/js/app.js`, `resources/js/bootstrap.js`, `resources/css/app.css`
  and `welcome.blade.php`). No route rendered it and the layout loads its CSS
  from a CDN, so `composer setup` was running `npm install && npm run build` to
  produce a bundle nothing requested.

## [1.3.0] — 2026-08-31

### Added

- **Badges as a column option.** `'status' => ['badges' => ['paid' => 'success',
  'overdue' => 'danger']]` draws the value as a coloured pill, replacing the
  render closure every project writes for a status column. Booleans take
  `[1 => ['success', 'Active'], 0 => ['danger', 'Inactive']]`, `'badges' => true`
  colours a value from the word itself, and a closure —
  `fn ($value, $record) => [$record->status_color, $record->status_name]` —
  covers the model-accessor pair. The label sits inside the markup, so exports
  still read as the word on screen. A theme that writes `{tone}` in its badge
  slot (`'badge' => 'badge badge-light-{tone}'`) decides where the tone goes.
- **`empty` column option**: what a null or blank cell reads as, e.g.
  `['empty' => 'Unassigned']`, instead of a closure that only supplies a
  fallback.
- **`$paramFilters`**: parameters bound straight to the query, so the usual
  `when($this->param('x'), fn ($q, $v) => $q->where(...))` chain in `query()`
  becomes one line each. Operators cover equality, comparisons, `contains`,
  `in`, `between` and `date`; a dotted column filters through a relation; and
  `'operator' => 'period'` reads a whole date picker from one parameter —
  `week`, `this_month`, `last_n_days:30`, or `custom` with its two companion
  parameters. Every name declared this way is a declared parameter.
- **Date patterns in either vocabulary.** `['format' => 'dd/mm/yyyy']`,
  `'dd/mm/yyyy hh:ii a'`, `'yyyy-mm-dd HH:mm'` — the way a spreadsheet writes a
  pattern — alongside PHP's own `d/m/Y`. `mm` between an hour and a second is
  minutes, words inside a pattern survive, and month and day names still follow
  the locale. On a column the package already knows is a date, the `date:`
  prefix is optional, so a bare `['format' => 'dd/mm/yyyy']` — which used to be
  ignored in silence — now does what it says.
- **`config('dynamic-table.formats')`**: application-wide default patterns for
  date, time and datetime columns, overriding the per-locale defaults from the
  language files.
- **`RowAction::route()` and `ToolbarAction::route()`**: the short form of
  `->url(fn ($record) => route('admin.companies.edit', $record))`.

### Changed

- **One config file.** Themes moved from `config/dynamic-table-themes.php` into
  the `themes` key of `config/dynamic-table.php`, next to the `theme` that
  selects one, and the second file is gone — along with its
  `dynamic-table-themes` publish tag and `dynamic-table:install --themes`. A
  themes file published by an earlier version is still read, so an upgrade
  drops no theme; move its contents under `themes` and delete it when
  convenient.

### Fixed

- The `badges` column option was accepted and carried through the resolver but
  never drawn. It now is.
- An enum cell drawn in the browser missed the `dt-badge-<value>` class the
  server-rendered one has; both now agree, and both honour a theme's `{tone}`.

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

- Tests across unit, feature, security and performance groups
- PHPStan (larastan) level 5, Laravel Pint
- Query-count and memory budgets asserted by the performance suite

[Unreleased]: https://github.com/shwaeki/DynamicTable/compare/v2.2.0...HEAD
[2.2.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v2.2.0
[2.1.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v2.1.0
[2.0.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v2.0.0
[1.3.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v1.3.0
[1.2.1]: https://github.com/shwaeki/DynamicTable/releases/tag/v1.2.1
[1.2.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v1.2.0
[1.1.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v1.1.0
[1.0.0]: https://github.com/shwaeki/DynamicTable/releases/tag/v1.0.0

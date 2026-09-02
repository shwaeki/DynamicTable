<?php

namespace App\Support;

use App\DynamicTables;
use Illuminate\Support\Collection;

/**
 * The index of interactive examples.
 *
 * Every entry points at a table class that actually exists and is actually
 * rendered — there are no illustrative-only entries, and a test asserts it.
 */
class ExampleRegistry
{
    /** @var Collection<int, Example>|null */
    private ?Collection $examples = null;

    /** @return Collection<int, Example> */
    public function all(): Collection
    {
        return $this->examples ??= collect($this->define());
    }

    public function find(string $id): ?Example
    {
        return $this->all()->firstWhere('id', $id);
    }

    public function first(): Example
    {
        return $this->all()->first();
    }

    /** @return Collection<string, Collection<int, Example>> */
    public function grouped(?string $search = null): Collection
    {
        return $this->all()
            ->filter(fn (Example $example): bool => $search === null || $example->matches($search))
            ->groupBy(static fn (Example $example): string => $example->categoryLabel());
    }

    /** @return list<Example> */
    private function define(): array
    {
        return [
            // ---------------------------------------------------------- Getting started
            new Example(
                id: 'basic',
                category: 'Getting started',
                title: 'Basic table',
                description: 'One property. Columns, types, relationships, search, sorting, pagination and the filter builder all come from the model.',
                table: DynamicTables\BasicUsersTable::class,
                notes: [
                    'department_id is discovered as a foreign key and shown as the department’s name, eager loaded — one extra query for the page, not one per row.',
                    'password and remember_token are in the model’s $hidden, so they can never be discovered, filtered, exported or edited.',
                    'The first eight columns are visible; the rest stay available to the column picker.',
                ],
                keywords: ['start', 'zero config', 'default', 'discovery'],
            ),
            new Example(
                id: 'custom-columns',
                category: 'Getting started',
                title: 'Custom columns',
                description: 'Declaring columns explicitly, with labels, formats and alignment.',
                table: DynamicTables\CustomColumnsTable::class,
                notes: [
                    'Four shapes are accepted and can be mixed: a bare path, path => label, path => options, and path => closure.',
                    'Formatting happens on the server, so exports carry exactly what the screen shows.',
                ],
                keywords: ['columns', 'label', 'format', 'currency'],
            ),
            new Example(
                id: 'search',
                category: 'Getting started',
                title: 'Search',
                description: 'Debounced global search across a deliberately small, safe set of columns — including one on a relationship.',
                table: DynamicTables\SearchTable::class,
                notes: [
                    'Without $searchable the package picks the first few visible text columns rather than scanning every string column in the schema.',
                    'company.name compiles to whereHas, not a join, so rows are never duplicated.',
                    '% and _ typed by the user are escaped, so nobody can widen their own match.',
                ],
                keywords: ['find', 'query', 'like'],
            ),
            new Example(
                id: 'pagination',
                category: 'Getting started',
                title: 'Pagination',
                description: 'Server-side pagination with custom page sizes.',
                table: DynamicTables\PaginationTable::class,
                notes: [
                    'A page size that is not in $perPageOptions is rejected server-side, so a crafted request cannot ask for the whole table.',
                    'Panels can be modals or side drawers — set $panels, or the panels.mode config key, and try both on the Builder page.',
                ],
                keywords: ['pages', 'per page', 'limit'],
            ),
            new Example(
                id: 'sorting',
                category: 'Getting started',
                title: 'Sorting',
                description: 'Single and multi-column sorting, including sorting by a related model.',
                table: DynamicTables\SortingTable::class,
                notes: [
                    'Shift-click a second header to add a secondary sort; up to three are applied.',
                    'Sorting by Category uses a correlated subquery rather than a join, so no DISTINCT is needed and rows are never duplicated.',
                    'A sort field that is not a sortable column is dropped and the table default is used — it never reaches orderBy().',
                ],
                keywords: ['order', 'asc', 'desc', 'multi'],
            ),

            // ---------------------------------------------------------- Relationships
            new Example(
                id: 'relationships',
                category: 'Relationships',
                title: 'Relationship columns',
                description: 'Four relationship columns across three relations, with no eager loading written by hand.',
                table: DynamicTables\RelationshipsTable::class,
                notes: [
                    'The developer panel shows the query count: 2 + one per relation, regardless of the page size.',
                    'Only singular relations (belongsTo, hasOne, morphOne) can be flattened into a column.',
                ],
                keywords: ['belongsto', 'hasone', 'eager', 'n+1', 'join'],
            ),
            new Example(
                id: 'nested-relationships',
                category: 'Relationships',
                title: 'Nested relationships',
                description: 'Two levels deep: user → department → company.',
                table: DynamicTables\NestedRelationshipsTable::class,
                notes: [
                    '$relationDepth also controls how far the filter builder and column picker walk, and is capped by config security.max_relation_depth.',
                    'Nested paths are eager loaded as one chain, so depth does not multiply queries.',
                ],
                keywords: ['nested', 'deep', 'depth'],
            ),
            new Example(
                id: 'relationship-filters',
                category: 'Relationships',
                title: 'Relationship filters',
                description: 'Filtering on fields that live on a related model, with searchable value pickers.',
                table: DynamicTables\RelationshipFiltersTable::class,
                notes: [
                    'Options come from a paginated, searchable DISTINCT query — never Department::all().',
                    '"not equals" on a relationship compiles to whereDoesntHave, so rows with no related record are included. Add "is not empty" if you want the stricter reading.',
                ],
                keywords: ['filter', 'relation', 'wherehas', 'options'],
            ),

            // ---------------------------------------------------------- Filters
            new Example(
                id: 'faceted-filters',
                category: 'Filters',
                title: 'Faceted counts',
                description: 'Filter values that carry their own counts: "Shipped (1,204)".',
                table: DynamicTables\FacetedFiltersTable::class,
                notes: [
                    'Open the filter panel and choose Status: each value shows how many rows it would keep.',
                    'Properly faceted — the current search and filters apply, except any condition already on that same column. Otherwise choosing "Shipped" would report every other status as zero.',
                    'Opt in per column with $filterCounts: it is one extra grouped query, run only when a dropdown is actually opened.',
                    'Computed and relationship columns are refused: a count has to be one GROUP BY on a real column, not a per-row calculation.',
                ],
                keywords: ['filter counts', 'facets', 'counts', 'faceted search'],
            ),
            new Example(
                id: 'filters',
                category: 'Filters',
                title: 'Advanced filter builder',
                description: 'Nested AND/OR condition groups, in the style of Dynamics 365 views.',
                table: DynamicTables\FiltersTable::class,
                notes: [
                    'Try: ( Status = Shipped OR Status = Delivered ) AND Total > 500.',
                    'Operators are derived from the field type and re-checked on the server; a forged operator is rejected, not applied.',
                    'The field catalogue is fetched lazily the first time the panel opens, so a table that never filters pays nothing for it.',
                ],
                keywords: ['advanced', 'builder', 'and', 'or', 'group', 'dynamics'],
            ),
            new Example(
                id: 'param-filters',
                category: 'Filters',
                title: 'Filters from your own page',
                description: 'A filter bar in the host application’s markup, driving the table with two data attributes.',
                table: DynamicTables\ParamFiltersTable::class,
                notes: [
                    'Each control carries data-dynamic-table-param (what it sets) and data-dynamic-table-table (the table key). Wrap the bar in data-dynamic-table-params="<key>" and the controls inside can drop the second one.',
                    'What each parameter does to the query is declared in $paramFilters — same-named column, a different column, a column through a relation, a comparison operator, or a period picker.',
                    'A filter is skipped when its parameter arrives empty, so an untouched bar costs nothing and adds no WHERE.',
                    'query() runs first and owns what the table may show at all; parameters narrow that result and can never widen it.',
                    'data-dynamic-table-params-reset="<key>" clears every bound control and reloads once.',
                    'The bar is the demo’s own markup — no package classes. In a Bootstrap or Tailwind admin it would be that framework’s.',
                ],
                extraFiles: ['resources/views/partials/order-filter-bar.blade.php'],
                keywords: ['param', 'parameter', 'external', 'toolbar', 'admin', 'data-dynamic-table-param', 'period'],
                partial: 'partials.order-filter-bar',
            ),
            new Example(
                id: 'column-search',
                category: 'Filters',
                title: 'Column search',
                description: 'A typed filter input under each column header.',
                table: DynamicTables\ColumnSearchTable::class,
                notes: [
                    'Each input is typed: text uses contains, numbers equality, dates whereDate, enums and booleans exact matching.',
                ],
                keywords: ['per column', 'inline filter'],
            ),
            new Example(
                id: 'date-filters',
                category: 'Filters',
                title: 'Date filters',
                description: 'Absolute and relative date operators.',
                table: DynamicTables\DateFiltersTable::class,
                notes: [
                    'Relative operators (today, this month, last year, in the last N days) are resolved on the server against the application timezone.',
                    'A date-only upper bound in a "between" is extended to the end of that day, which is almost always what the user meant.',
                ],
                keywords: ['date', 'between', 'relative', 'today', 'month'],
            ),
            new Example(
                id: 'presets',
                category: 'Filters',
                title: 'Developer presets',
                description: 'Views defined in code, shown alongside saved ones.',
                table: DynamicTables\PresetsTable::class,
                notes: [
                    'Presets are read-only for users and one can be marked as the default.',
                    'Precedence: the user’s default view, then the system default, then a preset, then the table’s own defaults.',
                ],
                keywords: ['preset', 'default view', 'code'],
            ),

            // ---------------------------------------------------------- Columns
            new Example(
                id: 'column-picker',
                category: 'Columns',
                title: 'Column picker, reordering & resizing',
                description: 'Choose columns, drag to reorder, drag a header edge to resize.',
                table: DynamicTables\ColumnPickerTable::class,
                notes: [
                    'Reordering works with the keyboard too: focus a row in the picker and use Alt + arrow keys.',
                    'Column choice, order and widths are table state, so a saved view stores them and an export follows them.',
                ],
                keywords: ['picker', 'reorder', 'resize', 'drag', 'visible'],
            ),
            new Example(
                id: 'header-menu',
                category: 'Columns',
                title: 'Column header menu',
                description: 'Click the chevron in a header for sort, group, filter, width, move and hide — the Dynamics 365 grid interaction.',
                table: DynamicTables\HeaderMenuTable::class,
                notes: [
                    'Sort labels follow the column type: A to Z for text, 1 to 9 for numbers, oldest to newest for dates.',
                    '"Group by this column" turns the value into a heading row. Grouping is expressed as a leading ORDER BY, so the database does the work and nothing is loaded into PHP to be grouped.',
                    '"Filter by this column" opens the filter builder with a condition on that column already added.',
                    'Column width can be set numerically here, or dragged straight from the edge of the header like a spreadsheet.',
                    'Move left / move right respect direction: in RTL, "left" moves the column the way it actually looks.',
                    'The menu only ever offers actions the table’s enabled features support — it cannot present something that would not work.',
                ],
                keywords: ['header', 'menu', 'dynamics', 'group', 'sort', 'width', 'move', 'hide', 'chevron'],
            ),
            new Example(
                id: 'renderers',
                category: 'Columns',
                title: 'Cell renderers & summary row',
                description: 'Progress bars, ratings, sparklines and chips — each one word in the column definition — with totals underneath.',
                table: DynamicTables\RenderersTable::class,
                notes: [
                    'These are formats, not a new concept: a column saying format progress:500 composes with everything a column already has.',
                    'All rendered on the server as inline SVG or a span with a class — no chart library, no client work, and they survive with JavaScript disabled.',
                    'A column using one is marked raw automatically, because the output is markup.',
                    'The text lives inside the markup, so an export of this table reads "3.7 / 5" and "10 / 500" rather than HTML — try the Export button.',
                    'A column saying summary sum puts an aggregate under it. It covers the whole filtered result, not the page, so filtering changes it and paging does not.',
                    'Print opens a page built for paper: repeating header, rows that never split across sheets, and the filters that produced it named at the top.',
                ],
                keywords: ['renderer', 'progress', 'rating', 'sparkline', 'chips', 'avatar', 'summary', 'total', 'print'],
            ),
            new Example(
                id: 'computed-columns',
                category: 'Columns',
                title: 'Computed attributes',
                description: 'An accessor from $appends, displayed and exported but never queried.',
                table: DynamicTables\ComputedColumnsTable::class,
                notes: [
                    'The Margin header is not clickable: a computed value does not exist in SQL, so sorting, searching, filtering and editing it are disabled rather than silently producing a wrong query.',
                ],
                keywords: ['accessor', 'appends', 'computed', 'virtual'],
            ),
            new Example(
                id: 'formatting',
                category: 'Columns',
                title: 'Formatting & custom rendering',
                description: 'Built-in formats, plus a render closure that returns HTML.',
                table: DynamicTables\FormattingTable::class,
                notes: [
                    'Output is escaped by default. A render closure that returns HTML needs "raw" => true, and must escape what it interpolates — note the e() call in the source.',
                    'Available formats: currency, number, percent, bytes, date, datetime, time, since, upper, lower, headline, truncate.',
                ],
                keywords: ['format', 'render', 'html', 'currency', 'badge'],
            ),

            // ---------------------------------------------------------- Views
            new Example(
                id: 'views',
                category: 'Views',
                title: 'Saved views',
                description: 'User views, system views and defaults — the Dynamics 365 model, backed by one database table.',
                table: DynamicTables\SavedViewsTable::class,
                notes: [
                    'Filter and reorder, then Views → "Save as new view".',
                    'Views → "Manage views" is where the default lives: click a star to make a view open by default, click it again to clear it. Renaming, sharing and deleting are in the same panel.',
                    'A user’s default and the shared system default are stored separately — starring your own view never disturbs everyone else’s.',
                    'Views store declarative state with a version field, never generated SQL, so they survive schema changes and can be migrated forward.',
                    'A view naming a field that no longer exists degrades: the invalid part is dropped and the table still renders.',
                    'The demo grants the manage-dynamic-table-system-views gate, so "Share with everyone" is available.',
                ],
                extraFiles: ['app/Providers/AppServiceProvider.php'],
                keywords: ['views', 'saved', 'system', 'default', 'share'],
            ),

            // ---------------------------------------------------------- Editing
            new Example(
                id: 'inline-edit',
                category: 'Editing',
                title: 'Inline editing',
                description: 'Double-click a cell. Enter saves and moves down, Tab moves across, Escape cancels.',
                table: DynamicTables\InlineEditTable::class,
                notes: [
                    'The editor matches the column type: a select for the enum and the boolean, a number input for price, a date picker for the release date.',
                    'Every row is authorised individually and the record is re-fetched through the table’s own query, so scoping cannot be bypassed.',
                ],
                keywords: ['edit', 'inline', 'cell', 'save'],
            ),
            new Example(
                id: 'validation',
                category: 'Editing',
                title: 'Validation',
                description: 'Laravel validation on inline edits, with the error shown on the offending cell.',
                table: DynamicTables\ValidationTable::class,
                notes: [
                    'Try a price of 0 or 9999, a SKU that is not SKU-00123 shaped, or an empty name.',
                    'Columns without declared rules still get rules derived from the schema: nullability, type, enum cases and string length.',
                    'The value is never written when validation fails; the cell reverts to the stored value.',
                ],
                keywords: ['validation', 'rules', 'error', 'invalid'],
            ),

            new Example(
                id: 'inline-create',
                category: 'Editing',
                title: 'Inline create',
                description: '"New" opens a blank row at the top of the table.',
                table: DynamicTables\InlineCreateTable::class,
                notes: [
                    'Creating is editing without a record yet: the same controls, the same column metadata and the same rules.',
                    'One request at the end rather than one per cell — a half-typed record never reaches the database.',
                    'newRecordDefaults() supplies the columns the blank row does not ask for, so the model is never saved incomplete.',
                    'Validation failures come back keyed by column, and mark the cell that caused them.',
                    'The create endpoint checks the "create" ability before anything else; the button is a convenience, not the control.',
                ],
                keywords: ['create', 'new', 'insert', 'add row', 'inline'],
            ),
            new Example(
                id: 'bulk-edit',
                category: 'Editing',
                title: 'Bulk edit',
                description: 'Select rows, then set the same values on all of them at once.',
                table: DynamicTables\BulkEditTable::class,
                notes: [
                    'Only columns marked editable are offered, and only the fields you tick are written — an untouched field is never sent.',
                    'Values are validated once, then applied record by record in chunks of 500, so a large selection does not become a large amount of memory.',
                    'Each record is authorised on its own and saved through the model, so policies apply, observers fire and audit trails see the change.',
                    'Records the viewer may not update are skipped rather than failing the whole operation.',
                    '"Select all matching" works here too: the selection is a mode, so a bulk edit can cover far more rows than the page shows.',
                ],
                keywords: ['bulk edit', 'mass update', 'selection', 'edit many'],
            ),

            // ---------------------------------------------------------- Actions
            new Example(
                id: 'toolbar-actions',
                category: 'Actions',
                title: 'Toolbar actions',
                description: 'Your own buttons beside Filters and Columns — links, or handlers that run on the server.',
                table: DynamicTables\ToolbarActionsTable::class,
                notes: [
                    'A toolbar action concerns the table rather than a row, so nothing is passed to it except the input you ask for.',
                    'Declared fields are collected in a dialog first and validated on the server with ordinary Laravel rules.',
                    'alignStart() puts a button beside the search box; primary() and danger() pick the theme’s button style rather than hard-coding colour.',
                    'ability() and visible() decide whether the button exists, and the endpoint re-checks before running: an action the viewer may not run is indistinguishable from one that does not exist.',
                    'The handler’s return string becomes the toast, and withoutRefresh() suppresses the reload for actions that change nothing.',
                ],
                keywords: ['toolbar', 'buttons', 'slot', 'custom action', 'header'],
            ),
            new Example(
                id: 'bulk-actions',
                category: 'Actions',
                title: 'Bulk actions',
                description: 'Select rows — or everything matching the current filters — and act on them.',
                table: DynamicTables\BulkActionsTable::class,
                notes: [
                    'Shift-click selects a range. Select the header checkbox, then "Select all matching" to go beyond the page.',
                    '"Select all" is stored as a mode, not a list of ids: the browser never holds or sends thousands of keys.',
                    'The server rebuilds the selection from the same filters that produced the page, so an id the user could not see cannot be smuggled in.',
                    'A handler receives an Eloquent builder, so you choose between one UPDATE and chunked iteration that fires model events.',
                ],
                keywords: ['bulk', 'actions', 'selection', 'select all', 'delete'],
            ),
            new Example(
                id: 'badges',
                category: 'Columns',
                title: 'Badges & empty cells',
                description: 'Coloured pills and placeholder text as column options, with no render closure anywhere.',
                table: DynamicTables\BadgesTable::class,
                notes: [
                    'The map is keyed by the stored value — the enum backing value, 1/0 for a boolean — and an entry is a tone, [tone, label], or [\'tone\' => …, \'label\' => …].',
                    'A tone on its own keeps the label the column already had, which is how the price column stays formatted as currency while the expensive rows are coloured.',
                    'A closure gets ($value, $record) and may return null, so only some rows are badged; that is the shape to use when the colour comes from a model accessor.',
                    'The label sits inside the markup rather than beside it, so an export of a badge column reads as the word on screen, not as HTML.',
                    'Tones are the theme\'s badge class plus dynamic-table-badge-<tone>. A template with its own badge CSS writes {tone} into the theme once: \'badge\' => \'badge badge-light-{tone}\'.',
                    '"empty" is the other half: what a null cell should say when a dash is not the right word.',
                ],
                keywords: ['badge', 'pill', 'status', 'tone', 'colour', 'color', 'empty', 'placeholder', 'null'],
            ),
            new Example(
                id: 'row-actions',
                category: 'Actions',
                title: 'Row actions & HTML cells',
                description: 'Buttons on every row, and small pieces of markup — images, badges — inside cells.',
                table: DynamicTables\RowActionsTable::class,
                notes: [
                    'Two kinds of action: a link goes wherever you point it, a handler is posted back to Laravel and run on the server.',
                    'Visibility is per record — "Publish" only appears on drafts, and "Discontinue" disappears once a product already is.',
                    'The server re-checks the action against the record before running it. A button having been rendered is never treated as permission.',
                    'After a handler runs, the row is repainted from the saved record; a delete reloads the page instead, because the row is gone.',
                    '"Publish" carries its own classes and draws its label: an action that names classes is not painted by the package, so it matches the buttons around it.',
                    'The thumbnail and the stock badge are render closures returning an HtmlString — which says "already safe HTML", so no raw flag is needed. Note the e() calls: escaping what you interpolate is still your job.',
                ],
                keywords: ['row actions', 'buttons', 'html', 'image', 'badge', 'link', 'delete', 'render'],
            ),
            new Example(
                id: 'authorization',
                category: 'Actions',
                title: 'Authorization & scoping',
                description: 'query() as a hard boundary, and an authorize() hook that denies deletion.',
                table: DynamicTables\AuthorizationTable::class,
                notes: [
                    'Only unpaid invoices exist as far as this table is concerned — for display, export, bulk actions and inline edits alike.',
                    'The delete action is not rendered *and* is rejected by the endpoint. Hiding UI is a courtesy; the server check is the control.',
                    '$hiddenColumns and $allowedColumns are the other half of this: a path that is hidden or outside the allowlist cannot be filtered, sorted, exported, edited or added from the column picker, however the request is crafted.',
                    'authorize() returns true/false to decide, or null to fall through to the model policy.',
                ],
                keywords: ['auth', 'policy', 'gate', 'scope', 'permission'],
            ),

            // ---------------------------------------------------------- Data
            new Example(
                id: 'enums',
                category: 'Data types',
                title: 'Enums',
                description: 'A backed enum cast, with zero configuration.',
                table: DynamicTables\EnumsTable::class,
                notes: [
                    'The cast is read from the model: the column renders as a badge, the filter offers exactly the enum’s cases, and the inline editor is a select.',
                    'OrderStatus defines label(), so "pending" displays as "Awaiting payment" everywhere — including exports.',
                ],
                extraFiles: ['app/Enums/OrderStatus.php'],
                keywords: ['enum', 'badge', 'status', 'cast'],
            ),
            new Example(
                id: 'soft-deletes',
                category: 'Data types',
                title: 'Soft deletes',
                description: 'One line in query(). There is no feature to switch on.',
                table: DynamicTables\SoftDeletesTable::class,
                notes: [
                    'Eloquent already says all three things — the default scope, withTrashed() and onlyTrashed() — so the package adds no flag of its own; query() is where it belongs.',
                    'What the package does contribute: a trashed row is recognised and struck through, whenever the model uses SoftDeletes.',
                ],
                keywords: ['soft delete', 'trashed', 'restore', 'archive'],
            ),
            new Example(
                id: 'json',
                category: 'Data types',
                title: 'JSON columns',
                description: 'Discovered, hidden by default, available on request.',
                table: DynamicTables\JsonTable::class,
                notes: [
                    'A JSON blob makes a poor default column, so it is discovered but hidden; ask for it explicitly and it renders compactly.',
                    'JSON fields support contains / does not contain filters.',
                ],
                keywords: ['json', 'array', 'cast', 'attributes'],
            ),

            // ---------------------------------------------------------- Excel
            new Example(
                id: 'export',
                category: 'Excel',
                title: 'Export',
                description: 'Current page, current view, all records, or the selection.',
                table: DynamicTables\ExportTable::class,
                notes: [
                    'Export follows the active view: its visible columns, in their order, with their formatting. Reorder the columns and export again to see it.',
                    'A relationship column exports its displayed value — "IT", not department_id = 4.',
                    'Rows stream in chunks, so memory is flat whether it is 10 rows or 10 million; past the configured threshold the export queues itself with progress.',
                    'Values beginning with =, +, - or @ are neutralised so a spreadsheet cannot execute exported data as a formula.',
                    'CSV works with no extra dependency; XLSX appears in the format list when openspout or PhpSpreadsheet is installed.',
                ],
                keywords: ['export', 'csv', 'xlsx', 'excel', 'download'],
            ),
            new Example(
                id: 'import',
                category: 'Excel',
                title: 'Import',
                description: 'Upload, map, preview, run — with per-row errors and a downloadable report.',
                table: DynamicTables\ImportTable::class,
                notes: [
                    'Start with "Download template": it carries the expected headings plus a hint row of formats and allowed values.',
                    'Headings are matched to columns automatically; correct any of them in the mapping step.',
                    'A column mapped to Company is resolved to company_id by looking the name up, with lookups cached for the whole import.',
                    'Rows are processed in chunked transactions: a bad row fails alone, a failed chunk rolls back, and nothing is wrapped in one enormous transaction.',
                    'The upload path is protected by an HMAC token issued during analysis, so the importer cannot be pointed at an arbitrary file.',
                ],
                keywords: ['import', 'upload', 'mapping', 'csv', 'excel', 'template'],
            ),

            // ---------------------------------------------------------- UI
            new Example(
                id: 'bootstrap',
                category: 'UI',
                title: 'Bootstrap 5 theme',
                description: 'The same table, rendered with Bootstrap classes.',
                table: DynamicTables\BootstrapTable::class,
                notes: [
                    'There is one Blade template and one JavaScript renderer; a theme is only a class map.',
                    'A Bootstrap application is never served Tailwind classes, and vice versa.',
                ],
                keywords: ['bootstrap', 'theme', 'css'],
            ),
            new Example(
                id: 'tailwind-theme',
                category: 'UI',
                title: 'Tailwind theme',
                description: 'The same table again, rendered with Tailwind utilities.',
                table: DynamicTables\TailwindTable::class,
                notes: [
                    'Three themes ship: `custom` — every other table on this site — `bootstrap` and `tailwind`. That is the whole list.',
                    'The map contributes layout, spacing and radii only. Colour comes from the package’s CSS tokens, so the table is readable in light and dark whether or not the application supports dark mode.',
                    'Tailwind’s dark: variants could not do that: under the default media strategy they follow the operating system and cannot be overridden per table.',
                ],
                keywords: ['theme', 'tailwind', 'css', 'utilities'],
            ),
            new Example(
                id: 'sticky-columns',
                category: 'UI',
                title: 'Sticky columns',
                description: 'Freeze the identifying columns. Scroll sideways: they stay.',
                table: DynamicTables\StickyColumnsTable::class,
                notes: [
                    'List the column keys in $stickyColumns; $stickyActions freezes the row buttons against the opposite edge.',
                    'CSS does the sticking, the package measures: widths change with the data, the column picker, resizing and the viewport, so offsets are computed rather than declared.',
                    'Offsets are written as logical insets, so the same columns freeze on the correct edge in RTL without a second code path.',
                    'One divider marks the boundary rather than a line between every frozen column.',
                ],
                keywords: ['sticky', 'frozen', 'freeze', 'pinned columns', 'horizontal scroll'],
            ),
            new Example(
                id: 'row-detail',
                category: 'UI',
                title: 'Row detail expander',
                description: 'A chevron on every row opens a panel underneath it.',
                table: DynamicTables\RowDetailTable::class,
                notes: [
                    'rowDetail() returns a string, an HtmlString or a Blade view — here, the order’s line items.',
                    'The panel is fetched the first time it is opened: it is usually the most expensive thing on screen, and most rows are never opened.',
                    'The record is re-fetched through the table’s own base query, so a detail cannot be read for a row this table would not show — and the "view" ability is checked as well.',
                    'Opened panels are cached until the rows change, so closing and reopening one costs nothing.',
                ],
                extraFiles: ['resources/views/partials/order-detail.blade.php'],
                keywords: ['detail', 'expander', 'child row', 'drilldown', 'chevron'],
            ),
            new Example(
                id: 'custom-theme',
                category: 'UI',
                title: 'A theme of your own',
                description: 'A complete theme in one array — no Blade files, no build step.',
                table: DynamicTables\CustomThemeTable::class,
                notes: [
                    'The whole theme is the "themes" key of config/dynamic-table.php — publish it with `php artisan vendor:publish --tag=dynamic-table-config` and edit it there. Nothing is registered in a service provider.',
                    'A name of your own starts from `custom`, so the array is only the slots you actually want to change; naming `bootstrap` or `tailwind` instead overrides that theme’s slots.',
                    'Keep the structural dynamic-table-* classes: they carry behaviour (sticky header, resize handles, dialog layout, RTL mirroring), not looks.',
                    'No colour in the map. The demo theme sets the CSS tokens (--dynamic-table-accent, --dynamic-table-radius …) under .dynamic-table-demo instead, which is what keeps it readable in light and dark.',
                ],
                extraFiles: ['config/dynamic-table.php'],
                keywords: ['theme', 'custom', 'css', 'brand'],
            ),
            new Example(
                id: 'responsive',
                category: 'UI',
                title: 'Responsive: collapsing columns',
                description: 'Narrow the window. Columns that no longer fit move into an expandable child row.',
                table: DynamicTables\ResponsiveTable::class,
                notes: [
                    'This is the behaviour DataTables Responsive gives a Yajra table, and what PowerGrid’s responsive feature does — nothing is lost, it moves.',
                    'Columns are measured, not guessed: the table hides the fewest columns that make it fit, so three short columns stay put on a phone while fifteen do not fit on a laptop.',
                    'Order matters through "priority" — lower survives longer. The first column defaults to priority 1, and $responsiveFixed pins Reference and Status here.',
                    'The child row clones the real cells, so badges, links and thumbnails look the same inside it.',
                    'The + control is a real button with aria-expanded, so this works from the keyboard and with a screen reader.',
                ],
                keywords: ['responsive', 'mobile', 'collapse', 'child row', 'priority', 'datatables', 'powergrid'],
            ),
            new Example(
                id: 'responsive-cards',
                category: 'UI',
                title: 'Responsive: card layout',
                description: 'The other small-screen strategy — stack each row into a labelled card.',
                table: DynamicTables\ResponsiveCardsTable::class,
                notes: [
                    'Better than collapsing when every field matters and there is no obvious hierarchy.',
                    'Switches at config(\'dynamic-table.responsive.breakpoint\'), 640px by default.',
                    'A third mode, "scroll", keeps the grid intact and scrolls horizontally; it loads no JavaScript at all.',
                ],
                keywords: ['responsive', 'cards', 'mobile', 'stacked'],
            ),
            new Example(
                id: 'rtl',
                category: 'UI',
                title: 'RTL',
                description: 'Right-to-left layout, mirrored properly rather than flipped.',
                table: DynamicTables\RtlTable::class,
                notes: [
                    'Use the language switcher in the header to see it with Arabic or Hebrew translations.',
                    'The stylesheet uses logical properties throughout, so the toolbar, header alignment, sort icons, resize handles, menus, the filter builder’s indentation and pagination all mirror.',
                    'Direction follows the app locale by default; this table forces it so you can compare.',
                ],
                keywords: ['rtl', 'arabic', 'hebrew', 'direction', 'locale', 'translation'],
            ),

            // ---------------------------------------------------------- Performance
            new Example(
                id: 'infinite-scroll',
                category: 'Performance',
                title: 'Infinite scroll',
                description: 'Scroll to the bottom and the next page appends itself.',
                table: DynamicTables\InfiniteScrollTable::class,
                notes: [
                    'A presentation choice on top of ordinary server-side paging: the same endpoint and the same LIMIT, appended instead of replaced.',
                    'Nothing ever loads "everything" — the browser holds only what you have scrolled past.',
                    'Because the pages are stitched together, no COUNT(*) runs: the footer reports the range rather than an invented total.',
                    'An IntersectionObserver watches a sentinel below the last row, so there is no scroll handler firing on every pixel.',
                    'Changing the search, a filter or the sort starts again from the first page, as it must.',
                ],
                keywords: ['infinite', 'scroll', 'lazy', 'append', 'pagination'],
            ),
            new Example(
                id: 'performance',
                category: 'Performance',
                title: 'Query budget',
                description: 'The developer panel, showing that the query count does not grow with the page size.',
                table: DynamicTables\LargeDatasetTable::class,
                notes: [
                    'Change the page size from 10 to 200 and watch the panel: still 2 queries plus one per eager-loaded relation.',
                    'This example also has url_state on, so search, page, sort and filters are mirrored into the query string and the back button works.',
                    'The panel is suppressed in production regardless of configuration.',
                ],
                keywords: ['performance', 'queries', 'n+1', 'panel', 'large', 'url'],
            ),
            new Example(
                id: 'scale-100k',
                category: 'Performance',
                title: '100,000 rows',
                description: 'An ordinary table over a properly indexed dataset. Nothing special is configured.',
                table: DynamicTables\Scale100kTable::class,
                notes: [
                    'Counting 100,000 rows costs almost nothing, so pagination stays length-aware: a real total and numbered pages.',
                    'Search is pointed at one indexed column. Searching six text columns at this size would be a table scan on every keystroke.',
                    'The default sort is on an indexed column, which is what keeps deep pages cheap.',
                    'Watch the developer panel: the query count and response time do not move as you page.',
                    'The same command seeds far larger sets — `dynamic-table:scale 1m` and `10m`. Past the pagination.count_threshold config value the table stops counting on its own and pages with previous/next and a row estimate, because at that size COUNT(*) costs more than the page does.',
                ],
                extraFiles: ['database/migrations/2026_01_01_000200_create_scale_tables.php'],
                keywords: ['large', 'scale', '100k', 'performance', 'index'],
                seedCommand: 'php artisan dynamic-table:scale 100k',
            ),

            // ---------------------------------------------------------- Everything
            new Example(
                id: 'everything',
                category: 'Everything',
                title: 'Every feature at once',
                description: 'Views, export, import, bulk actions, inline editing, the column picker, column search, soft deletes and URL state — on one table.',
                table: DynamicTables\EverythingTable::class,
                notes: [
                    'This is the ceiling of the configuration, and it is still one class of about sixty lines.',
                    'Compare it with the Basic example: the difference between "nothing" and "everything" is one array.',
                ],
                keywords: ['all', 'everything', 'kitchen sink', 'full'],
            ),
        ];
    }
}

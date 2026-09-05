<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\Columns\ColumnDefinition;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Modules\Export\ExportManager;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Query\RowFormatter;
use Shwaeki\DynamicTable\Views\ViewEngine;

/**
 * Builds the JSON contract shared by the initial server render and every
 * subsequent fetch. Keeping both on one shape means the browser has a single
 * code path and the first paint needs no round trip.
 */
class TablePayload
{
    public function __construct(
        protected QueryEngine $queries,
        protected RowFormatter $formatter,
    ) {}

    /** @return array<string, mixed> */
    public function data(DynamicTable $table, TableState $state): array
    {
        $started = microtime(true);
        $paginator = $this->queries->paginate($table, $state);
        $columns = $this->queries->activeColumns($table, $state);

        $counted = $paginator instanceof LengthAwarePaginator;

        $payload = [
            'rows' => $this->formatter->rows($paginator->items(), $columns, $table),
            'perPage' => $paginator->perPage(),
            'hasMore' => $paginator->hasMorePages(),
        ] + $this->position($table, $state, $paginator);

        // Columns the picker added were never in the boot payload, so the
        // browser has a key in its state with no definition to paint. Send the
        // definition with the rows — only for the ones it cannot already have,
        // so an ordinary table pays nothing for this.
        $declared = $table->resolvedColumns();
        $added = [];

        foreach ($columns as $column) {
            if (! isset($declared[$column->key])) {
                $added[] = $column->toArray() + ['visible' => true, 'added' => true];
            }
        }

        if ($added !== []) {
            $payload['columns'] = $added;
        }

        // "No records" and "no matches" are different sentences, and only the
        // second one has an action attached. The distinction is made here,
        // where the state is known, rather than guessed at in the browser —
        // and it asks what the *reader* narrowed, because Clear filters is the
        // only thing the sentence offers and that is all it can undo.
        if ($paginator->isEmpty()) {
            $payload['emptyReason'] = $state->isNarrowedByReader() ? 'filtered' : 'none';
        }

        // Aggregates for the columns that asked for one, over the whole
        // filtered set rather than the page — a total that changes when you
        // turn the page is not a total.
        $summaries = $this->queries->summaries($table, $state);

        if ($summaries !== []) {
            $payload['summaries'] = $this->formatter->summaries($summaries, $columns);

            // The same aggregates once per group heading. Only worth asking for
            // when the table is grouped *and* something opted into a summary,
            // so an ungrouped table and a grouped one with no totals both cost
            // exactly what they cost before.
            $groups = $this->groupTotals($table, $state, $paginator->items(), $payload['rows'], $columns);

            if ($groups !== []) {
                $payload['groupSummaries'] = $groups;
            }
        }

        if ($state->warnings !== []) {
            $payload['warnings'] = array_values(array_unique($state->warnings));
        }

        if ($this->panelEnabled()) {
            $payload['debug'] = [
                'ms' => round((microtime(true) - $started) * 1000, 2),
                'memory' => round(memory_get_peak_usage(true) / 1048576, 1),
                'relations' => $this->queries->relationsFor($table, $state),
            ];
        }

        return $payload;
    }

    /**
     * Where in the result this page sits, in whichever terms its paginator has.
     *
     * A length-aware paginator answers all of it. A simple one knows there is a
     * next page but not how many there are, so the total and the last page are
     * absent rather than guessed at. A cursor-paged one knows neither — that is
     * the point of it — so the count is asked for separately when the table
     * wants one, and the page number is the client's own tally, used for the
     * range label and nothing else. It never reaches the query.
     *
     * @param  LengthAwarePaginator<int, Model>|Paginator<int, Model>|CursorPaginator<int, Model>  $paginator
     * @return array<string, mixed>
     */
    protected function position(DynamicTable $table, TableState $state, LengthAwarePaginator|Paginator|CursorPaginator $paginator): array
    {
        if ($paginator instanceof CursorPaginator) {
            $total = $table->countsRows() ? $this->queries->count($table, $state) : null;
            $rows = count($paginator->items());
            $from = (($state->page - 1) * $paginator->perPage()) + 1;

            return [
                'total' => $total,
                'lastPage' => $total === null ? null : (int) max(1, ceil($total / $paginator->perPage())),
                'counted' => $total !== null,
                'estimate' => null,
                'page' => $state->page,
                'from' => $rows === 0 ? null : $from,
                'to' => $rows === 0 ? null : $from + $rows - 1,
                'nextCursor' => $paginator->nextCursor()?->encode(),
            ];
        }

        $counted = $paginator instanceof LengthAwarePaginator;

        return [
            'total' => $counted ? $paginator->total() : null,
            'lastPage' => $counted ? $paginator->lastPage() : null,
            'counted' => $counted,
            // Without a count, an approximate size is still far more useful
            // than none — but only while nothing narrows the set, because the
            // estimate describes the table, not the filtered result.
            'estimate' => $counted || ! $state->isUnfiltered()
                ? null
                : app(TableEstimator::class)->rows($table->newModel()),
            'page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * The full boot payload: schema plus the first page of data.
     *
     * @return array<string, mixed>
     */
    public function boot(DynamicTable $table, TableState $state, ?array $data = null): array
    {
        $features = $table->features();
        $resolved = $table->resolvedColumns();
        $responsive = $table->responsive();

        $modules = $features->modules();

        // Only the collapsing and card layouts need JavaScript; plain
        // horizontal scrolling is pure CSS and loads nothing.
        if ($responsive !== null && in_array($responsive['mode'], ['collapse', 'cards'], true)) {
            $modules[] = 'responsive';
        }

        // The header menu has to be switched on *and* have something to offer:
        // sorting, grouping, filtering, resizing, reordering or hiding.
        if ($features->has(Feature::HEADER_MENU) && $features->any(
            Feature::SORTING,
            Feature::GROUPING,
            Feature::FILTERS,
            Feature::COLUMN_RESIZE,
            Feature::COLUMN_REORDER,
            Feature::COLUMN_PICKER,
        )) {
            $modules[] = 'header-menu';
        }

        $columns = [];

        foreach ($resolved as $key => $column) {
            $columns[] = $column->toArray() + ['visible' => in_array($key, $state->columns, true)];
        }

        // Columns the picker added are not in the declared set, so they are
        // appended here — otherwise the browser would have a key in its state
        // with no definition to paint.
        foreach ($state->columns as $key) {
            if (isset($resolved[$key])) {
                continue;
            }

            $added = $table->columnFor($key);

            if ($added !== null) {
                $columns[] = $added->toArray() + ['visible' => true, 'added' => true];
            }
        }

        return array_filter([
            'key' => $table->key(),
            'title' => $table->title(),
            'theme' => $table->theme(),
            'classes' => Theme::classes($table->theme()),
            'direction' => $table->direction(),
            'scheme' => $table->scheme(),
            'panels' => $table->panels(),
            'locale' => app()->getLocale(),
            'features' => $features->toArray(),
            'modules' => $modules,
            'responsive' => $responsive,
            'columns' => $columns,
            'perPageOptions' => $table->perPageOptions(),
            'viewName' => $this->viewName($table, $state),
            'actions' => array_map(
                static fn ($action): array => $action->toArray(),
                $table->availableActions(),
            ),
            'rowActions' => array_map(
                static fn ($action): array => $action->toArray(),
                $table->availableRowActions(),
            ),
            'toolbarActions' => array_map(
                static fn ($action): array => $action->toArray(),
                $table->availableToolbarActions(),
            ),
            'sticky' => $table->stickyColumnKeys(),
            // The column a drag writes to. Whether a drag is possible *now*
            // also depends on the sort, which the reader can change without
            // asking the server, so that half of the question is the client's.
            'reorderable' => $table->reorderColumn(),
            'rowClick' => $table->rowClickTrigger(),
            // The ids this viewer keeps at the top. Sent so the rows can be
            // marked; the ordering itself already happened in SQL.
            'pinned' => app(PinMemory::class)->ids($table),
            'stickyActions' => $table->hasStickyActions(),
            'filterCounts' => $table->filterCountKeys(),
            'editableColumns' => array_values(array_map(
                static fn ($column): string => $column->key,
                $table->editableColumns(),
            )),
            'paginationStyle' => $table->paginationStyle(),
            'maxHeight' => $table->maxHeight(),
            'permissions' => [
                'edit' => $features->has(Feature::INLINE_EDIT) && $table->can('update'),
                'create' => $features->has(Feature::INLINE_CREATE) && $table->can('create'),
                'bulkEdit' => $features->has(Feature::BULK_EDIT) && $table->can('update'),
                'export' => $features->has(Feature::EXPORT) && $table->can('export'),
                'print' => $features->has(Feature::PRINT) && $table->can('export'),
                'import' => $features->has(Feature::IMPORT) && $table->can('import'),
                'views' => $features->has(Feature::SAVED_VIEWS),
                'systemViews' => $features->has(Feature::SAVED_VIEWS) && $table->can('manage-system-views'),
            ],
            'exportFormats' => $features->has(Feature::EXPORT)
                ? app(ExportManager::class)->supportedFormats()
                : null,
            'searchDebounce' => (int) config('dynamic-table.search.debounce', 350),
            'scrollOnPage' => (bool) config('dynamic-table.pagination.scroll_on_page', true),
            'endpoints' => $this->endpoints(),
            'state' => $state->toArray(),
            'data' => $data,
            'labels' => $this->labels(),
            'panel' => $this->panelEnabled(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The label for the view picker: the active view's name, or the table's
     * title when no view is applied.
     */
    protected function viewName(DynamicTable $table, TableState $state): string
    {
        if ($state->view === null || ! $table->hasFeature(Feature::SAVED_VIEWS)) {
            return $table->title();
        }

        $view = app(ViewEngine::class)->find($table, $state->view);

        return $view === null ? $table->title() : $view->name;
    }

    /** @return array<string, string> */
    public function endpoints(): array
    {
        return [
            'data' => route('dynamic-table.data'),
            'fields' => route('dynamic-table.fields'),
            'options' => route('dynamic-table.options'),
            'print' => route('dynamic-table.print'),
            'edit' => route('dynamic-table.edit'),
            'action' => route('dynamic-table.action'),
            'rowAction' => route('dynamic-table.row-action'),
            'toolbarAction' => route('dynamic-table.toolbar-action'),
            'create' => route('dynamic-table.create'),
            'bulkEdit' => route('dynamic-table.bulk-edit'),
            'rowDetail' => route('dynamic-table.row-detail'),
            'reorder' => route('dynamic-table.reorder'),
            'pin' => route('dynamic-table.pin'),
            'views' => route('dynamic-table.views.index'),
            'export' => route('dynamic-table.export'),
            'import' => route('dynamic-table.import'),
            'progress' => route('dynamic-table.progress'),
        ];
    }

    /**
     * The strings the browser draws.
     *
     * The operator names live in a language file of their own — they belong to
     * a closed enum rather than to the table chrome — but the filter builder
     * and the column header menu are the things that draw them, and both run in
     * the browser. Shipping them under one key is what keeps "does not contain"
     * from arriving as "not contains" in every language.
     *
     * @return array<string, mixed>
     */
    public function labels(): array
    {
        return array_replace(
            ['operators' => (array) trans('dynamic-table::operators')],
            (array) trans('dynamic-table::table'),
        );
    }

    /**
     * Group subtotals, keyed the way the renderers will look them up.
     *
     * Both renderers start a new group when the *formatted* value in the row
     * changes — "Marketing", not the department id that produced it — so the
     * totals have to arrive under that same key. The database can only group by
     * the raw column, so this pairs the two: the raw value is read straight off
     * the model with getRawOriginal(), which is what the aggregate query will
     * see as well, and the result is re-keyed to what the reader sees.
     *
     * @param  array<int, Model>  $items
     * @param  list<array<string, mixed>>  $rows
     * @param  list<ColumnDefinition>  $columns
     * @return array<string, array<string, string>>
     */
    protected function groupTotals(DynamicTable $table, TableState $state, array $items, array $rows, array $columns): array
    {
        if ($state->group === null || ! $table->hasFeature(Feature::GROUPING)) {
            return [];
        }

        $column = $table->columnFor($state->group);

        if ($column === null || $column->isComputed() || $column->isRelational()) {
            return [];
        }

        $name = (string) ($column->field->column ?? $column->field->name);
        $items = array_values($items);
        $rows = array_values($rows);

        // Keyed by what the reader sees, valued by what the database groups on.
        // First one wins: a group is contiguous, so the pairing is settled by
        // its first row.
        $raw = [];

        foreach ($items as $index => $item) {
            $key = QueryEngine::groupKey($rows[$index]['c'][$state->group] ?? null);

            $raw[$key] ??= $item->getRawOriginal($name);
        }

        $totals = $this->queries->groupSummaries($table, $state, array_values($raw));
        $formatted = [];

        foreach ($raw as $key => $value) {
            $found = $totals[QueryEngine::groupKey($value)] ?? null;

            if ($found !== null) {
                $formatted[$key] = $this->formatter->summaries($found, $columns);
            }
        }

        return $formatted;
    }

    protected function panelEnabled(): bool
    {
        return (bool) config('dynamic-table.performance.panel', false)
            && ! app()->isProduction();
    }
}

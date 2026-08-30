<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Modules\Export\ExportManager;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Query\RowFormatter;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Support\Theme;

/**
 * A printable page for the current view.
 *
 * Print is deliberately shaped like an export rather than like a screenshot:
 * the same scopes (this page, the current view, everything, the selection),
 * the same column set, the same server-side formatting. What comes out of the
 * printer therefore matches what a CSV of the same view would contain, which
 * is the property people actually rely on when they file the paper.
 *
 * It is a GET route so the browser can open it in a tab and so a printed page
 * can be re-printed with a refresh; the state travels in the query string,
 * where it is re-derived exactly as it is for every other endpoint.
 */
class PrintController extends Controller
{
    use ResolvesTable;

    public function __invoke(Request $request, ExportManager $exports, RowFormatter $formatter): View
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::PRINT);
        abort_unless($table->can('export'), 403, __('dynamic-table::table.errors.forbidden'));

        $state = $this->state($request, $table);
        $scope = in_array($request->input('scope'), ['page', 'view', 'all', 'selected'], true)
            ? (string) $request->input('scope')
            : 'view';

        $columns = $exports->columns($table, $state);
        $query = $exports->query($table, $state, $scope);

        // A printed page is a finite thing. Past the cap the reader wants a
        // spreadsheet, not four hundred sheets of paper, so the limit is
        // applied rather than silently ignored.
        $limit = (int) config('dynamic-table.print.max_rows', 2000);
        // "This page" means the page the reader is looking at, exactly as it
        // does for an export — not the whole filtered set.
        $records = $scope === 'page'
            ? $query->forPage($state->page, $state->perPage)->get()
            : $query->limit($limit + 1)->get();

        $truncated = $records->count() > $limit;

        return view($table->printView(), [
            'table' => $table,
            'title' => $table->title(),
            'columns' => array_map(
                static fn ($column): array => $column->toArray(),
                $columns,
            ),
            'rows' => $formatter->rows($records->take($limit), $columns, $table),
            'summaries' => $formatter->summaries(
                app(QueryEngine::class)->summaries($table, $state),
                $columns,
            ),
            'state' => $state,
            'scope' => $scope,
            'truncated' => $truncated,
            'limit' => $limit,
            'printedAt' => now(),
            'theme' => $table->theme(),
            'classes' => Theme::classes($table->theme()),
            'direction' => $table->direction(),
            'meta' => $this->describeFilters($table, $state),
            // Open, print, close — unless the caller asked to look first
            // (?auto=0), which is what you want while editing the template.
            'auto' => $request->boolean('auto', (bool) config('dynamic-table.print.auto', true)),
        ]);
    }

    /**
     * A one-line description of what is on the page.
     *
     * A printout without it is a table of numbers nobody can place a week
     * later — which filters produced this is part of the document, not chrome.
     *
     * @return list<string>
     */
    protected function describeFilters(DynamicTable $table, TableState $state): array
    {
        $lines = [];

        if ($state->search !== '') {
            $lines[] = __('dynamic-table::table.print.search', ['term' => $state->search]);
        }

        $conditions = $this->countConditions($state->rawFilters);

        if ($conditions > 0) {
            $lines[] = trans_choice('dynamic-table::table.print.filters', $conditions, ['count' => $conditions]);
        }

        if ($state->sort !== []) {
            $labels = [];

            foreach ($state->sort as $entry) {
                $column = $table->columnFor($entry['field']);

                if ($column !== null) {
                    $labels[] = $column->label.' '.($entry['direction'] === 'desc' ? '↓' : '↑');
                }
            }

            if ($labels !== []) {
                $lines[] = __('dynamic-table::table.print.sorted', ['columns' => implode(', ', $labels)]);
            }
        }

        return $lines;
    }

    /** @param array<mixed> $filters */
    protected function countConditions(array $filters): int
    {
        $count = 0;

        foreach ($filters['conditions'] ?? [] as $child) {
            $count += isset($child['conditions']) ? $this->countConditions($child) : 1;
        }

        return $count;
    }
}

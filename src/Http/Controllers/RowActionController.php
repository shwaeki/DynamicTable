<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shwaeki\DynamicTable\Events\RowActionExecuted;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Query\RowFormatter;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Runs a single row's action.
 *
 * The record is re-fetched through the table's own base query, and the action's
 * visibility and authorisation are evaluated again against that record — the
 * button having been rendered is not treated as permission.
 */
class RowActionController extends Controller
{
    use ResolvesTable;

    public function __construct(
        protected QueryEngine $queries,
        protected RowFormatter $formatter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::ROW_ACTIONS);

        $name = (string) $request->input('action', '');
        $action = $table->findRowAction($name);

        abort_if($action === null, 404, 'Unknown action.');
        abort_if($action->isLink(), 422, 'That action is a link.');

        $state = $this->state($request, $table);
        $query = $this->queries->baseQuery($table, $state);
        $model = $query->getModel();

        $record = $query->where($model->getQualifiedKeyName(), $request->input('id'))->first();

        abort_if($record === null, 404, __('dynamic-table::table.errors.not_found'));
        abort_unless($action->appliesTo($table, $record), 403, __('dynamic-table::table.errors.forbidden'));

        $result = $action->run($record, (array) $request->input('input', []));

        event(new RowActionExecuted($table->key(), $name, $record));

        // The row may no longer exist, or may no longer match the filters, so
        // the browser is told to refresh rather than handed a stale row.
        $fresh = $record->exists ? $record->fresh() : null;

        return response()->json([
            'ok' => true,
            'refresh' => $action->refreshes(),
            'deleted' => $fresh === null,
            'row' => $fresh === null ? null : $this->formatter->row(
                $fresh,
                $this->queries->activeColumns($table, $state),
                $table,
            ),
            'message' => is_string($result) ? $result : null,
        ]);
    }
}

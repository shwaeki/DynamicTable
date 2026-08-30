<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Shwaeki\DynamicTable\Events\BulkActionExecuted;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Runs a bulk action against the current selection.
 *
 * The selection is rebuilt on the server from the same filters that produced
 * the visible page, intersected with the browser's id list. "Select all
 * matching" therefore never sends millions of ids over the wire, and an id the
 * user could not see cannot be smuggled into the set.
 */
class ActionController extends Controller
{
    use ResolvesTable;

    public function __construct(protected QueryEngine $queries) {}

    public function __invoke(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::BULK_ACTIONS);

        $name = (string) $request->input('action', '');
        $action = $table->findAction($name);

        abort_if($action === null, 404, 'Unknown action.');
        abort_unless($action->isAuthorized($table), 403);

        $state = $this->state($request, $table);

        abort_unless($state->hasSelection(), 422, __('dynamic-table::table.errors.no_selection'));

        $input = (array) $request->input('input', []);
        $rules = $action->rules();

        if ($rules !== []) {
            $input = Validator::make($input, $rules)->validate();
        }

        $query = $this->queries->selectionQuery($table, $state);

        $result = $action->run($query, $input);
        $affected = is_int($result) ? $result : (is_countable($result) ? count($result) : 0);

        event(new BulkActionExecuted($table->key(), $name, $affected, $input));

        return response()->json([
            'ok' => true,
            'affected' => $affected,
            'message' => is_string($result)
                ? $result
                : trans_choice('dynamic-table::table.actions.completed', $affected, ['count' => $affected]),
        ]);
    }
}

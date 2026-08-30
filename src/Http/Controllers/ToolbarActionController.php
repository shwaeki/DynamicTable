<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Support\Feature;

/** Runs a toolbar action — one that concerns the table rather than a row. */
class ToolbarActionController extends Controller
{
    use ResolvesTable;

    public function __invoke(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::TOOLBAR_ACTIONS);

        $name = (string) $request->input('action', '');
        $action = $table->findToolbarAction($name);

        // findToolbarAction only returns actions this viewer may run, so an
        // unavailable one is indistinguishable from one that does not exist.
        abort_if($action === null, 404, 'Unknown action.');
        abort_if($action->isLink(), 422, 'That action is a link.');

        $input = (array) $request->input('input', []);
        $rules = $action->rules();

        if ($rules !== []) {
            $input = Validator::make($input, $rules)->validate();
        }

        $result = $action->run($table, $input);

        return response()->json([
            'ok' => true,
            'refresh' => $action->refreshes(),
            'message' => is_string($result) ? $result : null,
        ]);
    }
}

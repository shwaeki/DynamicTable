<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\PinMemory;

/**
 * Pinning a row to the top of the table, for this viewer.
 *
 * The id is not looked up. It does not need to be: a pin only ever changes the
 * ORDER BY of a query that already refuses rows outside the table's own scope,
 * so an id for a record the reader cannot see pins nothing and reveals nothing.
 * Checking would cost a query per pin to prevent nothing.
 */
class PinController extends Controller
{
    use ResolvesTable;

    public function __invoke(Request $request, PinMemory $pins): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::PINNED_ROWS);

        if ($request->boolean('clear')) {
            $pins->clear($table);

            return response()->json(['pinned' => []]);
        }

        $id = $request->input('id');

        abort_if(! is_scalar($id) || (string) $id === '', 422, __('dynamic-table::table.errors.generic'));

        return response()->json(['pinned' => $pins->toggle($table, (string) $id)]);
    }
}

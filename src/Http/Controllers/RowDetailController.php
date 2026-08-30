<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * The expanded detail for one row.
 *
 * Fetched on demand rather than rendered with the page: a detail panel is
 * usually the most expensive thing on the screen, and most rows are never
 * expanded. The record comes through the table's own base query, so a detail
 * cannot be read for a row the table would not show.
 */
class RowDetailController extends Controller
{
    use ResolvesTable;

    public function __invoke(Request $request, QueryEngine $queries): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::ROW_DETAIL);

        $query = $queries->baseQuery($table, $this->state($request, $table));
        $model = $query->getModel();

        $record = $query->where($model->getQualifiedKeyName(), $request->input('id'))->first();

        abort_if($record === null, 404, __('dynamic-table::table.errors.not_found'));
        abort_unless($table->can('view', $record), 403, __('dynamic-table::table.errors.forbidden'));

        $detail = $table->rowDetail($record);

        return response()->json([
            'html' => match (true) {
                $detail === null => '',
                $detail instanceof View => $detail->render(),
                $detail instanceof Htmlable => $detail->toHtml(),
                default => e((string) $detail),
            },
        ]);
    }
}

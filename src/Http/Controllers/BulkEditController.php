<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Shwaeki\DynamicTable\Events\BulkActionExecuted;
use Shwaeki\DynamicTable\Events\RowUpdated;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\NormalisesInput;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Set the same values on every selected record.
 *
 * Deliberately record-by-record rather than one mass UPDATE: each row is
 * authorised individually and saved through the model, so policies apply,
 * observers fire and audit trails see the change. Chunked, so the selection can
 * be large without the memory following it.
 */
class BulkEditController extends Controller
{
    use NormalisesInput;
    use ResolvesTable;

    public function __invoke(Request $request, QueryEngine $queries): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::BULK_EDIT);
        abort_unless($table->can('update'), 403, __('dynamic-table::table.errors.forbidden'));

        $state = $this->state($request, $table);
        abort_unless($state->hasSelection(), 422, __('dynamic-table::table.errors.no_selection'));

        $fields = (array) $request->input('fields', []);

        [$attributes, $rules, $columns] = $this->prepare($table, $fields);

        abort_if($attributes === [], 422, __('dynamic-table::table.errors.nothing_to_save'));

        // Validate once: the same values are applied to every record.
        $validator = Validator::make($attributes, $rules, $table->validationMessages());

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'errors' => $this->keyErrors($validator->errors()->messages(), $columns),
            ], 422);
        }

        $values = $validator->validated();
        $updated = 0;
        $skipped = 0;

        $queries->selectionQuery($table, $state)->chunkById(500, function ($records) use ($table, $values, &$updated, &$skipped): void {
            foreach ($records as $record) {
                if (! $table->can('update', $record)) {
                    $skipped++;

                    continue;
                }

                $record->forceFill($values);

                if (! $record->isDirty()) {
                    continue;
                }

                $dirty = $record->getDirty();
                $record->save();
                $updated++;

                event(new RowUpdated($table->key(), $record, $dirty));
            }
        });

        event(new BulkActionExecuted($table->key(), 'bulk-edit', $updated, $values));

        return response()->json([
            'ok' => true,
            'updated' => $updated,
            'skipped' => $skipped,
            'message' => trans_choice('dynamic-table::table.actions.completed', $updated, ['count' => $updated]),
        ]);
    }
}

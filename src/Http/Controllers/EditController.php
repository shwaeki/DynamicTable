<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Shwaeki\DynamicTable\Events\RowUpdated;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\NormalisesInput;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Query\RowFormatter;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Inline and bulk cell editing.
 *
 * The record is always re-fetched through the table's own base query, so a
 * table scoped with query() can never be used to edit a row outside that
 * scope, no matter what id the browser sends.
 */
class EditController extends Controller
{
    use NormalisesInput;
    use ResolvesTable;

    public function __construct(
        protected QueryEngine $queries,
        protected RowFormatter $formatter,
    ) {}

    public function update(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::INLINE_EDIT);

        $changes = $request->input('changes');

        if (! is_array($changes) || $changes === []) {
            $changes = [[
                'id' => $request->input('id'),
                'field' => $request->input('field'),
                'value' => $request->input('value'),
            ]];
        }

        abort_if(count($changes) > 500, 422, 'Too many changes in one request.');

        $state = $this->state($request, $table);
        $baseQuery = $this->queries->baseQuery($table, $state);
        $model = $baseQuery->getModel();

        // Group by record so each row is validated and saved exactly once.
        $grouped = [];

        foreach ($changes as $change) {
            if (! is_array($change) || ! isset($change['id'], $change['field'])) {
                continue;
            }

            $grouped[(string) $change['id']][(string) $change['field']] = $change['value'] ?? null;
        }

        abort_if($grouped === [], 422, 'Nothing to update.');

        $records = (clone $baseQuery)
            ->whereIn($model->getQualifiedKeyName(), array_keys($grouped))
            ->get()
            ->keyBy(fn ($record): string => (string) $record->getKey());

        $updated = [];
        $errors = [];

        DB::connection($model->getConnectionName())->transaction(function () use (
            $table, $grouped, $records, &$updated, &$errors, $state
        ): void {
            foreach ($grouped as $id => $fields) {
                $record = $records->get((string) $id);

                if ($record === null) {
                    $errors[$id] = ['_' => [__('dynamic-table::table.errors.not_found')]];

                    continue;
                }

                if (! $table->can('update', $record)) {
                    $errors[$id] = ['_' => [__('dynamic-table::table.errors.forbidden')]];

                    continue;
                }

                [$attributes, $rules, $columns] = $this->prepare($table, $fields);

                // Every requested field was rejected as non-editable: report it
                // rather than silently succeeding, so client bugs surface.
                if ($attributes === []) {
                    $errors[$id] = ['_' => [__('dynamic-table::table.errors.forbidden')]];

                    continue;
                }

                $validator = Validator::make($attributes, $rules, $table->validationMessages());

                if ($validator->fails()) {
                    $errors[$id] = $this->keyErrors($validator->errors()->messages(), $columns);

                    continue;
                }

                $record->fill($validator->validated());

                if (! $record->isDirty()) {
                    continue;
                }

                $dirty = $record->getDirty();
                $record->save();

                event(new RowUpdated($table->key(), $record, $dirty));

                $active = $this->queries->activeColumns($table, $state);
                $updated[] = $this->formatter->row($record->fresh() ?? $record, $active, $table);
            }
        });

        return response()->json([
            'rows' => $updated,
            'errors' => $errors,
            'ok' => $errors === [],
        ], $errors === [] ? 200 : 422);
    }
}

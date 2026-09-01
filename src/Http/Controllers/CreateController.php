<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Shwaeki\DynamicTable\Events\RowCreated;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\NormalisesInput;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Query\RowFormatter;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Creates a record from the blank row at the top of the table.
 *
 * The same columns, the same rules and the same normalisation as inline
 * editing, so a value that would be rejected on edit is rejected on create.
 */
class CreateController extends Controller
{
    use NormalisesInput;
    use ResolvesTable;

    public function __construct(
        protected QueryEngine $queries,
        protected RowFormatter $formatter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::INLINE_CREATE);
        abort_unless($table->can('create'), 403, __('dynamic-table::table.errors.forbidden'));

        $fields = (array) $request->input('fields', []);

        [$attributes, $rules, $columns] = $this->prepare($table, $fields);

        abort_if($attributes === [], 422, __('dynamic-table::table.errors.nothing_to_save'));

        $validator = Validator::make($attributes, $rules, $table->validationMessages());

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'errors' => $this->keyErrors($validator->errors()->messages(), $columns),
            ], 422);
        }

        $record = $table->newModel();
        $record->forceFill($table->newRecordDefaults())->forceFill($validator->validated())->save();

        event(new RowCreated($table->key(), $record));

        $state = $this->state($request, $table);

        return response()->json([
            'ok' => true,
            'row' => $this->formatter->row(
                $record->fresh() ?? $record,
                $this->queries->activeColumns($table, $state),
                $table,
            ),
        ], 201);
    }
}

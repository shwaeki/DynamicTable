<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Shwaeki\DynamicTable\Events\RowUpdated;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\TableState;

/**
 * Dragging a row to a new position.
 *
 * The rows on the page hold a set of position values. A drag does not invent
 * new numbers for them — it permutes which row sits in which of the values that
 * were already there. Two things follow, and both are why it is done this way:
 * nothing outside the page is renumbered, so a drag on page 4 of a hundred
 * costs the same as a drag on page 1; and the values cannot collide with rows
 * the reader cannot see, because none of them are new.
 *
 * Every record is re-fetched through the table's own base query, so a drag can
 * never move a row the table would not have shown.
 */
class ReorderController extends Controller
{
    use ResolvesTable;

    public function __construct(protected QueryEngine $queries) {}

    public function __invoke(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::ROW_REORDER);

        $column = $table->reorderColumn();

        abort_if($column === null, 422, __('dynamic-table::table.errors.not_reorderable'));

        $state = $this->state($request, $table);

        // The drag describes a position in the order on screen. If the table is
        // not sorted by the position column, that order is somebody else's.
        abort_unless($this->sortedByPosition($state, $column), 422, __('dynamic-table::table.errors.not_reorderable'));

        $ids = $request->input('ids');

        abort_if(! is_array($ids) || $ids === [], 422, __('dynamic-table::table.errors.generic'));
        abort_if(count($ids) > $state->perPage, 422, __('dynamic-table::table.errors.generic'));

        $definition = $table->columnFor($column);
        $name = (string) ($definition?->field->column ?? $definition?->field->name);

        $query = $this->queries->baseQuery($table, $state);
        $model = $query->getModel();

        $records = $query->whereIn($model->getQualifiedKeyName(), $ids)->get()->keyBy($model->getKeyName());

        // Every id has to be a row this table would show, and one the viewer may
        // change. A partial reorder is refused rather than half-applied: the
        // reader would be left looking at an order that is neither the old one
        // nor the one they asked for.
        foreach ($ids as $id) {
            abort_unless($records->has($id), 404, __('dynamic-table::table.errors.not_found'));
            abort_unless($table->can('update', $records->get($id)), 403, __('dynamic-table::table.errors.forbidden'));
        }

        $slots = $this->slots($records->all(), $name, count($ids));
        $descending = ($state->sort[0]['direction'] ?? 'asc') === 'desc';

        if ($descending) {
            $slots = array_reverse($slots);
        }

        $changed = [];

        DB::transaction(function () use ($ids, $records, $slots, $name, &$changed): void {
            foreach (array_values($ids) as $index => $id) {
                $record = $records->get($id);

                if ($record->getAttribute($name) === $slots[$index]) {
                    continue;
                }

                $record->setAttribute($name, $slots[$index]);
                $record->save();

                $changed[] = $record;
            }
        });

        foreach ($changed as $record) {
            event(new RowUpdated($table->key(), $record, [$name => $record->getAttribute($name)]));
        }

        return response()->json([
            'moved' => count($changed),
            'positions' => array_combine(array_values($ids), $slots),
        ]);
    }

    /**
     * The position values the page already held, in ascending order.
     *
     * Nulls and duplicates would leave two rows claiming one slot, so they are
     * replaced from above the largest value present — which keeps every row
     * that already had a distinct position exactly where it was, and gives the
     * others somewhere unambiguous to go.
     *
     * @param  array<array-key, Model>  $records
     * @return list<int>
     */
    protected function slots(array $records, string $name, int $count): array
    {
        $values = [];

        foreach ($records as $record) {
            $value = $record->getAttribute($name);

            $values[] = is_numeric($value) ? (int) $value : null;
        }

        $used = array_values(array_unique(array_filter($values, static fn (?int $value): bool => $value !== null)));
        sort($used);

        $next = ($used === [] ? 0 : max($used)) + 1;

        while (count($used) < $count) {
            $used[] = $next++;
        }

        return array_slice($used, 0, $count);
    }

    /** Is the table ordered by the position column, and by nothing before it? */
    protected function sortedByPosition(TableState $state, string $column): bool
    {
        return ($state->sort[0]['field'] ?? null) === $column;
    }
}

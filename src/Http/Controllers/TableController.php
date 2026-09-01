<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Metadata\FieldType;
use Shwaeki\DynamicTable\Metadata\MetadataEngine;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\StateMemory;
use Shwaeki\DynamicTable\Support\TablePayload;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Views\ViewEngine;

class TableController extends Controller
{
    use ResolvesTable;

    public function __construct(
        protected TablePayload $payload,
        protected MetadataEngine $metadata,
        protected QueryEngine $queries,
        protected StateMemory $memory,
    ) {}

    /** One page of rows for the current state. */
    public function data(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $state = $this->state($request, $table);

        // Every page of rows is also the answer to "how was it left?", so this
        // is where the memory is kept up to date — not only on first paint.
        $this->memory->remember($table, $state);

        return response()->json([
            'data' => $this->payload->data($table, $state),
            'state' => $state->toArray(),
        ]);
    }

    /**
     * The filterable/pickable field tree.
     *
     * Loaded lazily the first time the filter builder or column picker opens,
     * so a plain table never pays for relationship introspection.
     */
    public function fields(Request $request): JsonResponse
    {
        $table = $this->table($request);

        // Both the filter builder and the column picker read this catalogue, so
        // either feature is reason enough to serve it. Requiring filters alone
        // left a table with a column picker and '-filters' unable to add one.
        abort_unless(
            $table->features()->any(Feature::FILTERS, Feature::COLUMN_PICKER),
            403,
            'Neither filters nor the column picker is enabled for this table.',
        );

        $depth = $table->relationDepth();
        $tree = $this->metadata->tree($table->modelClass(), $depth, $table->hiddenColumnPaths());

        $allowed = $table->allowedColumnPaths();

        if ($allowed !== []) {
            $tree = array_values(array_filter(array_map(static function (array $group) use ($allowed): array {
                $group['fields'] = array_values(array_filter(
                    $group['fields'],
                    static fn (array $field): bool => in_array($field['path'], $allowed, true),
                ));

                return $group;
            }, $tree), static fn (array $group): bool => $group['fields'] !== []));
        }

        return response()->json([
            'groups' => $tree,
            'views' => app(ViewEngine::class)->payloadFor($table),
        ]);
    }

    /**
     * Values for a select/remote-select filter input.
     *
     * Never loads every related record: enum options come from the cast, and
     * relationship options are a paginated, searchable DISTINCT query.
     */
    public function options(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $path = (string) $request->input('field', '');
        $search = trim((string) $request->input('search', ''));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 25;

        $field = $this->metadata->resolve($table->modelClass(), $path);

        abort_if($field === null, 404, 'Unknown field.');
        abort_if(in_array($path, $table->hiddenColumnPaths(), true), 403, __('dynamic-table::table.errors.forbidden'));

        $allowed = $table->allowedColumnPaths();
        abort_if($allowed !== [] && ! in_array($path, $allowed, true), 403, __('dynamic-table::table.errors.forbidden'));

        $facets = $this->facetCounts($table, $request, $path);

        if ($field->type === FieldType::Enum || $field->options !== []) {
            $options = $field->options;

            if ($search !== '') {
                $options = array_values(array_filter(
                    $options,
                    static fn (array $option): bool => stripos($option['label'], $search) !== false,
                ));
            }

            return response()->json([
                'options' => $this->withCounts($options, $facets),
                'more' => false,
            ]);
        }

        if ($field->type === FieldType::Boolean) {
            return response()->json([
                'options' => $this->withCounts([
                    ['value' => 1, 'label' => (string) __('dynamic-table::table.yes')],
                    ['value' => 0, 'label' => (string) __('dynamic-table::table.no')],
                ], $facets),
                'more' => false,
            ]);
        }

        // Distinct values of the target column, which for a relationship field
        // means distinct values on the related table.
        $modelClass = $field->relatedModel ?? $table->modelClass();

        /** @var Model $model */
        $model = new $modelClass;
        $column = (string) ($field->column ?? $field->name);

        $query = $model->newQuery()
            ->select($model->qualifyColumn($column))
            ->whereNotNull($model->qualifyColumn($column))
            ->distinct()
            ->orderBy($model->qualifyColumn($column));

        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $query->where($model->qualifyColumn($column), 'like', '%'.$escaped.'%');
        }

        $rows = $query->limit($perPage + 1)->offset(($page - 1) * $perPage)->pluck($column);
        $more = $rows->count() > $perPage;

        $options = $rows->take($perPage)
            ->map(static fn (mixed $value): array => ['value' => $value, 'label' => (string) $value])
            ->values()
            ->all();

        return response()->json([
            'options' => $this->withCounts($options, $facets),
            'more' => $more,
        ]);
    }

    /**
     * How many rows each value of this column would match.
     *
     * Properly faceted: the current search and filters apply, *except* any
     * condition on this same column — otherwise selecting "Active" would report
     * every other status as zero, which is the classic faceting mistake.
     *
     * One grouped query, only for columns the table opted in, and only when a
     * dropdown is actually opened.
     *
     * @return array<string, int>
     */
    protected function facetCounts(DynamicTable $table, Request $request, string $path): array
    {
        $key = str_replace('.', '__', $path);

        if (! in_array($key, $table->filterCountKeys(), true)) {
            return [];
        }

        $column = $table->column($key);

        if ($column === null || $column->isComputed() || $column->isRelational()) {
            return [];
        }

        $input = (array) $request->input('state', []);
        $input['filters'] = $this->withoutConditionsOn($input['filters'] ?? [], $path);

        $state = TableState::fromArray($input, $table);
        $name = (string) ($column->field->column ?? $column->field->name);
        $qualified = $table->newModel()->qualifyColumn($name);

        return $this->queries->build($table, $state)
            ->reorder()
            ->select($qualified)
            ->selectRaw('count(*) as dt_facet_count')
            ->groupBy($qualified)
            ->pluck('dt_facet_count', $name)
            ->mapWithKeys(static fn (mixed $count, mixed $value): array => [(string) $value => (int) $count])
            ->all();
    }

    /**
     * Strip conditions on one field from a filter tree, at any depth.
     *
     * @param  array<mixed>  $filters
     * @return array<mixed>
     */
    protected function withoutConditionsOn(mixed $filters, string $path): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $children = $filters['conditions'] ?? $filters['filters'] ?? (array_is_list($filters) ? $filters : null);

        if ($children === null || ! is_array($children)) {
            $field = $filters['field'] ?? $filters['column'] ?? null;

            return $field === $path ? [] : $filters;
        }

        $kept = [];

        foreach ($children as $child) {
            $cleaned = $this->withoutConditionsOn($child, $path);

            if ($cleaned !== []) {
                $kept[] = $cleaned;
            }
        }

        if ($kept === []) {
            return [];
        }

        return array_is_list($filters)
            ? $kept
            : ['logic' => $filters['logic'] ?? 'and'] + ['conditions' => $kept];
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @param  array<string, int>  $counts
     * @return list<array<string, mixed>>
     */
    protected function withCounts(array $options, array $counts): array
    {
        if ($counts === []) {
            return $options;
        }

        return array_map(
            static fn (array $option): array => $option + ['count' => $counts[(string) $option['value']] ?? 0],
            $options,
        );
    }
}

<?php

namespace Shwaeki\DynamicTable\Query;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Shwaeki\DynamicTable\Columns\ColumnDefinition;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Filters\FilterEngine;
use Shwaeki\DynamicTable\Filters\ParamFilters;
use Shwaeki\DynamicTable\Metadata\FieldType;
use Shwaeki\DynamicTable\Metadata\MetadataEngine;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\TableState;
use Throwable;

/**
 * Turns a table plus its validated state into exactly one paginated query.
 *
 * Design rules:
 *  - the developer's own query() is applied first and never rewritten;
 *  - relationships needed for display are eager loaded in one extra query each,
 *    so a page of N rows costs 1 + (relations) queries regardless of N;
 *  - relationship sorting uses a correlated subquery on the foreign key rather
 *    than a join, so rows are never duplicated and no DISTINCT is needed.
 */
class QueryEngine
{
    public function __construct(protected FilterEngine $filters) {}

    /** @return Builder<Model> */
    public function build(DynamicTable $table, TableState $state): Builder
    {
        $query = $this->baseQuery($table, $state);

        $this->applySelect($query, $table, $state);
        $this->applyEagerLoads($query, $table, $state);
        $this->applySearch($query, $table, $state);
        $this->applyColumnSearch($query, $table, $state);
        $this->filters->apply($query, $state->filters);
        $this->applySort($query, $table, $state);

        return $query;
    }

    /**
     * A page of results.
     *
     * Returns a simple paginator when the table has opted out of counting:
     * at tens of millions of rows the COUNT(*) behind a length-aware paginator
     * costs far more than fetching the page itself, and "previous / next" is a
     * better answer than a slow total.
     *
     * @return LengthAwarePaginator<int, Model>|Paginator<int, Model>
     */
    public function paginate(DynamicTable $table, TableState $state): LengthAwarePaginator|Paginator
    {
        $query = $this->build($table, $state);

        if (! $table->countsRows()) {
            return $query->simplePaginate(perPage: $state->perPage, page: $state->page);
        }

        return $query->paginate(perPage: $state->perPage, page: $state->page);
    }

    /**
     * A query restricted to the current selection, used by bulk actions and
     * "export selected". The selection is re-derived from the filters on the
     * server; the browser only ever contributes an id allowlist or blocklist.
     *
     * @return Builder<Model>
     */
    /**
     * Aggregates for the columns that asked for one.
     *
     * Deliberately over the *whole filtered result*, not the page: a total that
     * changes when you turn the page is not a total. It is one extra query, and
     * only when at least one visible column opted in, so a table with no
     * summary row runs exactly what it ran before.
     *
     * @return array<string, float|int|null>
     */
    public function summaries(DynamicTable $table, TableState $state): array
    {
        $columns = array_filter(
            $this->activeColumns($table, $state),
            static fn (ColumnDefinition $column): bool => $column->isSummable(),
        );

        if ($columns === []) {
            return [];
        }

        /*
         * The same filtered set as the page, but without its eager loads.
         *
         * build() attaches one for every relation column, and they would each
         * run again here for a single row of aggregates whose relations are
         * never read — turning one summary column into one query plus one per
         * relation, on every request.
         */
        $query = $this->build($table, $state)->reorder()->setEagerLoads([]);
        $selects = [];
        $grammar = $query->getQuery()->getGrammar();

        foreach ($columns as $column) {
            $name = (string) ($column->field->column ?? $column->field->name);
            $qualified = $grammar->wrap($query->qualifyColumn($name));

            // The aggregate name comes from a closed list in the resolver, and
            // the column is wrapped by the grammar, so nothing here is user
            // text however the request was crafted.
            $selects[] = strtoupper($column->summary)."({$qualified}) as ".$grammar->wrap('dt_'.$column->key);
        }

        $row = (array) $query->selectRaw(implode(', ', $selects))->first()?->getAttributes();

        $summaries = [];

        foreach ($columns as $column) {
            $value = $row['dt_'.$column->key] ?? null;

            $summaries[$column->key] = $value === null ? null : $value + 0;
        }

        return $summaries;
    }

    public function selectionQuery(DynamicTable $table, TableState $state): Builder
    {
        $query = $this->baseQuery($table, $state);

        $this->applySearch($query, $table, $state);
        $this->applyColumnSearch($query, $table, $state);
        $this->filters->apply($query, $state->filters);

        $selection = $state->selection;
        $key = $query->getModel()->getQualifiedKeyName();

        if (($selection['mode'] ?? 'include') === 'exclude') {
            if (($selection['ids'] ?? []) !== []) {
                $query->whereNotIn($key, $selection['ids']);
            }

            return $query;
        }

        return $query->whereIn($key, $selection['ids'] ?? []);
    }

    /** @return Builder<Model> */
    public function baseQuery(DynamicTable $table, TableState $state): Builder
    {
        $model = $table->newModel();
        $query = $model->newQuery();

        foreach ($table->scopes() as $scope) {
            if (is_string($scope) && method_exists($model, 'scope'.ucfirst($scope))) {
                $query->{$scope}();
            }
        }

        $query = ParamFilters::apply($table, $table->query($query));

        return $query;
    }

    /**
     * Narrow the SELECT list when it is safe to do so.
     *
     * Accessors can read any attribute, so the moment a computed column is in
     * play we fall back to selecting everything rather than guessing at the
     * accessor's dependencies.
     */
    protected function applySelect(Builder $query, DynamicTable $table, TableState $state): void
    {
        $meta = $table->metadata();
        $columns = $this->activeColumns($table, $state);
        $needed = [$meta->keyName];

        foreach ($columns as $column) {
            if ($column->isComputed()) {
                return;
            }

            if (! $column->isRelational()) {
                $needed[] = (string) ($column->field->column ?? $column->field->name);
            }
        }

        // Foreign keys for every eager-loaded belongsTo must survive the select.
        foreach ($this->relationsFor($table, $state) as $relation) {
            $root = explode('.', $relation)[0];
            $definition = $meta->relation($root);

            if ($definition?->type === 'BelongsTo' && $definition->foreignKey !== null) {
                $needed[] = $definition->foreignKey;
            }
        }

        if ($meta->usesSoftDeletes) {
            $needed[] = 'deleted_at';
        }

        $needed = array_values(array_unique(array_filter(
            $needed,
            static fn (string $name): bool => $name !== '',
        )));

        // Only worth it when we are actually dropping columns.
        if (count($needed) >= count($meta->columnNames())) {
            return;
        }

        $query->select(array_map(
            static fn (string $name): string => $meta->table.'.'.$name,
            $needed,
        ));
    }

    protected function applyEagerLoads(Builder $query, DynamicTable $table, TableState $state): void
    {
        $relations = $this->relationsFor($table, $state);

        if ($relations !== []) {
            $query->with($relations);
        }
    }

    /**
     * Every relationship path needed to render the active columns, plus any
     * the developer declared. Deduplicated and de-nested so "department" and
     * "department.manager" become a single eager load chain.
     *
     * @return list<string>
     */
    public function relationsFor(DynamicTable $table, TableState $state): array
    {
        $paths = [];

        foreach ($this->activeColumns($table, $state) as $column) {
            $key = $column->field->relationKey();

            if ($key !== null) {
                $paths[$key] = true;
            }
        }

        foreach ($table->eagerLoad() as $relation) {
            $paths[$relation] = true;
        }

        $paths = array_keys($paths);

        // Drop prefixes that are already covered by a deeper path.
        return array_values(array_filter($paths, static function (string $path) use ($paths): bool {
            foreach ($paths as $other) {
                if ($other !== $path && str_starts_with($other, $path.'.')) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<ColumnDefinition> */
    public function activeColumns(DynamicTable $table, TableState $state): array
    {
        $columns = [];

        foreach ($state->columns as $key) {
            $column = $table->columnFor($key);

            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    protected function applySearch(Builder $query, DynamicTable $table, TableState $state): void
    {
        $term = $state->search;

        if ($term === '' || mb_strlen($term) < (int) config('dynamic-table.search.min_length', 1)) {
            return;
        }

        $paths = $table->searchablePaths();

        if ($paths === []) {
            return;
        }

        $like = '%'.$this->filters->escapeLike($term).'%';

        $query->where(function (Builder $nested) use ($paths, $like, $table): void {
            foreach ($paths as $path) {
                $field = app(MetadataEngine::class)
                    ->resolve($table->modelClass(), $path);

                if ($field === null) {
                    continue;
                }

                $column = (string) ($field->column ?? $field->name);

                if ($field->isRelational()) {
                    $nested->orWhereHas(
                        (string) $field->relationKey(),
                        function (Builder $related) use ($column, $like): void {
                            $related->where($related->qualifyColumn($column), 'like', $like);
                        }
                    );

                    continue;
                }

                $nested->orWhere($nested->qualifyColumn($column), 'like', $like);
            }
        });
    }

    protected function applyColumnSearch(Builder $query, DynamicTable $table, TableState $state): void
    {
        foreach ($state->columnSearch as $key => $term) {
            $column = $table->columnFor($key);

            if ($column === null || $column->isComputed()) {
                continue;
            }

            $field = $column->field;
            $name = (string) ($field->column ?? $field->name);
            $type = $field->type;

            $apply = function (Builder $target, string $qualified) use ($term, $type): void {
                if ($type === FieldType::Boolean) {
                    $value = filter_var($term, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                    if ($value !== null) {
                        $target->where($qualified, $value);
                    }

                    return;
                }

                if ($type->isNumeric()) {
                    if (is_numeric($term)) {
                        $target->where($qualified, $term);
                    }

                    return;
                }

                if ($type === FieldType::Enum) {
                    $target->where($qualified, $term);

                    return;
                }

                if ($type->isTemporal()) {
                    $target->whereDate($qualified, $term);

                    return;
                }

                $target->where($qualified, 'like', '%'.$this->filters->escapeLike($term).'%');
            };

            if ($field->isRelational()) {
                $query->whereHas(
                    (string) $field->relationKey(),
                    function (Builder $related) use ($apply, $name): void {
                        $apply($related, $related->qualifyColumn($name));
                    }
                );

                continue;
            }

            $apply($query, $query->qualifyColumn($name));
        }
    }

    protected function applySort(Builder $query, DynamicTable $table, TableState $state): void
    {
        if (! $table->hasFeature(Feature::SORTING) && $state->sort === []) {
            return;
        }

        $applied = 0;

        // Grouping is expressed as a leading sort, so the database does the
        // work and the browser only has to notice where the value changes.
        // Nothing is ever loaded into PHP just to be grouped.
        $group = $state->group === null ? null : $table->columnFor($state->group);

        if ($group !== null) {
            if (! $group->isComputed()) {
                $name = (string) ($group->field->column ?? $group->field->name);

                if ($group->isRelational()) {
                    $applied += $this->applyRelationSort($query, $table, (string) $group->field->relationKey(), $name, 'asc') ? 1 : 0;
                } else {
                    $query->orderBy($query->qualifyColumn($name), 'asc');
                    $applied++;
                }
            }
        }

        foreach ($state->sort as $entry) {
            $column = $table->columnFor($entry['field']);

            if ($column === null || $column->isComputed()) {
                continue;
            }

            $direction = $entry['direction'];
            $field = $column->field;
            $name = (string) ($field->column ?? $field->name);

            if (! $field->isRelational()) {
                $query->orderBy($query->qualifyColumn($name), $direction);
                $applied++;

                continue;
            }

            if ($this->applyRelationSort($query, $table, (string) $field->relationKey(), $name, $direction)) {
                $applied++;
            }
        }

        // A deterministic tiebreaker keeps pagination stable across pages.
        $key = $query->getModel()->getQualifiedKeyName();

        if ($applied === 0) {
            $query->orderBy($key, 'desc');

            return;
        }

        $query->orderBy($key, 'asc');
    }

    /**
     * Sort by a column on a singular relationship using a correlated subquery.
     * Only depth-1 belongsTo/hasOne are supported: anything deeper would need a
     * join whose cost is not obvious from the call site, so we skip it instead.
     */
    protected function applyRelationSort(
        Builder $query,
        DynamicTable $table,
        string $relationPath,
        string $column,
        string $direction,
    ): bool {
        if (str_contains($relationPath, '.')) {
            return false;
        }

        try {
            $model = $table->newModel();

            if (! method_exists($model, $relationPath)) {
                return false;
            }

            $relation = $model->{$relationPath}();
        } catch (Throwable) {
            return false;
        }

        $related = $relation->getRelated();
        $subQuery = $related->newQuery()
            ->select($related->qualifyColumn($column))
            ->limit(1);

        if ($relation instanceof BelongsTo) {
            $subQuery->whereColumn(
                $related->qualifyColumn($relation->getOwnerKeyName()),
                $model->qualifyColumn($relation->getForeignKeyName()),
            );
        } elseif ($relation instanceof HasOne) {
            $subQuery->whereColumn(
                $related->qualifyColumn($relation->getForeignKeyName()),
                $model->qualifyColumn($relation->getLocalKeyName()),
            );
        } else {
            return false;
        }

        $query->orderBy($subQuery, $direction);

        return true;
    }
}

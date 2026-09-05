<?php

namespace Shwaeki\DynamicTable\Query;

use Illuminate\Contracts\Pagination\CursorPaginator;
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
use Shwaeki\DynamicTable\Support\PinMemory;
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
        $this->applyAggregates($query, $table, $state);
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
    public function paginate(DynamicTable $table, TableState $state): LengthAwarePaginator|Paginator|CursorPaginator
    {
        $query = $this->build($table, $state);

        /*
         * A cursor answers "the rows after these", so it can only ever move
         * forward from somewhere it has been. Asking for page 4 cold — a link
         * with ?page=4, or a page button on a table that shows them — has no
         * cursor to start from, and offset is the only thing that can answer
         * it. Sequential scrolling, which is all of infinite scrolling, always
         * carries one.
         */
        $keyset = $table->paginationStyle() === 'infinite'
            && ($state->cursor !== null || $state->page === 1)
            && $this->supportsKeyset($query, $table);

        if ($keyset) {
            return $query->cursorPaginate(perPage: $state->perPage, cursorName: 'cursor', cursor: $state->cursor);
        }

        if (! $table->countsRows()) {
            return $query->simplePaginate(perPage: $state->perPage, page: $state->page);
        }

        return $query->paginate(perPage: $state->perPage, page: $state->page);
    }

    /**
     * How many rows the current filters match.
     *
     * Only the cursor path needs this — the other paginators count as part of
     * their own work. Ordering and eager loads are dropped first: neither
     * changes a count, and both cost real time on a large table.
     */
    public function count(DynamicTable $table, TableState $state): int
    {
        return $this->build($table, $state)->reorder()->setEagerLoads([])->toBase()->getCountForPagination();
    }

    /**
     * Can this query be paged by its sort values rather than by an offset?
     *
     * It matters most where it is used. An infinitely scrolled table reads
     * page after page of the same result while people are inserting into it,
     * and OFFSET answers that by counting from the top every time: a row
     * inserted above the window shifts everything down, so the reader sees a
     * row twice and never sees another — and page 400 costs the database 10,000
     * rows it throws away.
     *
     * Keyset paging asks "the next rows after these values" instead, which is
     * both stable under inserts and indexable. The price is that every ORDER BY
     * has to be a plain column this query can also compare in a WHERE, so a
     * sort on a relation or on an aggregate — both subqueries — falls back to
     * offset rather than producing SQL no database will accept.
     *
     * @param  Builder<Model>  $query
     */
    protected function supportsKeyset(Builder $query, DynamicTable $table): bool
    {
        $orders = $query->getQuery()->orders ?? [];

        if ($orders === []) {
            return false;
        }

        $columns = array_flip($table->metadata()->columnNames());
        $prefix = $table->metadata()->table.'.';

        foreach ($orders as $order) {
            $column = $order['column'] ?? null;

            // A raw order has no 'column' at all, and an Expression is a
            // subquery wearing one.
            if (! is_string($column)) {
                return false;
            }

            $name = str_starts_with($column, $prefix) ? substr($column, strlen($prefix)) : $column;

            if (! isset($columns[$name])) {
                return false;
            }
        }

        return true;
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

        // select([]) first, because build() may have narrowed the select to the
        // columns the page needs. Appending an aggregate to that list asks the
        // database for plain columns and an aggregate in one row without a
        // GROUP BY, which MySQL refuses outright under ONLY_FULL_GROUP_BY and
        // every other engine answers with an arbitrary row's values.
        $row = (array) $query->select([])->selectRaw(implode(', ', $selects))->first()?->getAttributes();

        $summaries = [];

        foreach ($columns as $column) {
            $value = $row['dt_'.$column->key] ?? null;

            /*
             * Numbers arrive from the driver as strings and are wanted as
             * numbers. A min or a max, though, is whatever the column holds — a
             * date, a name, a reference — and coercing that is how MAX over a
             * datetime column became "A non-numeric value encountered".
             */
            $summaries[$column->key] = match (true) {
                $value === null => null,
                is_numeric($value) => $value + 0,
                default => $value,
            };
        }

        return $summaries;
    }

    /**
     * The same aggregates as summaries(), once per group heading on this page.
     *
     * Two decisions worth stating, because both could have gone the other way:
     *
     * The values are the *whole group's*, not the visible slice's. A group cut
     * in half by a page break still reports what the group holds, because that
     * is what the heading claims to describe — "Engineering" is a department,
     * not "the four engineers who fit on this page".
     *
     * The query is bounded to the groups on this page. Grouping a large table
     * by a high-cardinality column would otherwise aggregate every group in the
     * result to label the handful the reader can see.
     *
     * A computed or relational group column gets nothing: there is no single
     * column to GROUP BY, and inventing one would mean a join this engine
     * deliberately does not make.
     *
     * @param  list<mixed>  $values  raw group values on this page, in row order
     * @return array<string, array<string, float|int|null>> keyed by groupKey()
     */
    public function groupSummaries(DynamicTable $table, TableState $state, array $values): array
    {
        $group = $state->group === null ? null : $table->columnFor($state->group);

        if ($group === null || $values === [] || $group->isComputed() || $group->isRelational()) {
            return [];
        }

        $columns = array_filter(
            $this->activeColumns($table, $state),
            static fn (ColumnDefinition $column): bool => $column->isSummable(),
        );

        if ($columns === []) {
            return [];
        }

        $query = $this->build($table, $state)->reorder()->setEagerLoads([]);
        $grammar = $query->getQuery()->getGrammar();

        $groupColumn = $query->qualifyColumn((string) ($group->field->column ?? $group->field->name));
        $selects = [$grammar->wrap($groupColumn).' as '.$grammar->wrap('dt_group')];

        foreach ($columns as $column) {
            $name = (string) ($column->field->column ?? $column->field->name);
            $qualified = $grammar->wrap($query->qualifyColumn($name));

            $selects[] = strtoupper($column->summary)."({$qualified}) as ".$grammar->wrap('dt_'.$column->key);
        }

        $present = array_values(array_filter($values, static fn (mixed $value): bool => $value !== null));
        $nullable = count($present) !== count($values);

        $query->where(function ($inner) use ($groupColumn, $present, $nullable): void {
            if ($present !== []) {
                $inner->whereIn($groupColumn, $present);
            }

            // A null group is a group — "no department" is a heading a reader
            // sees — and whereIn never matches one.
            if ($nullable) {
                $present === [] ? $inner->whereNull($groupColumn) : $inner->orWhereNull($groupColumn);
            }
        });

        $rows = $query->select([])
            ->selectRaw(implode(', ', $selects))
            ->groupBy($groupColumn)
            ->get();

        $summaries = [];

        foreach ($rows as $row) {
            $attributes = $row->getAttributes();
            $totals = [];

            foreach ($columns as $column) {
                $value = $attributes['dt_'.$column->key] ?? null;

                // Same reasoning as summaries(): a max is whatever the column
                // holds, and only a number is coerced to one.
                $totals[$column->key] = match (true) {
                    $value === null => null,
                    is_numeric($value) => $value + 0,
                    default => $value,
                };
            }

            $summaries[self::groupKey($attributes['dt_group'] ?? null)] = $totals;
        }

        return $summaries;
    }

    /**
     * One group value as an array key.
     *
     * Null gets a sentinel rather than the empty string, so a column holding
     * both null and '' does not report one group's total under the other's
     * heading — they are separate groups on screen and have to stay separate
     * here.
     */
    public static function groupKey(mixed $value): string
    {
        return match (true) {
            $value === null => "\0null",
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            // Nothing else can be keyed against a value the database returned,
            // so it gets a key that matches nothing and the group simply shows
            // no subtotal. Better than a wrong one under the wrong heading.
            default => "\0unkeyable",
        };
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

            // An aggregate has no column to select — it arrives as a subquery
            // that applyAggregates() adds. Naming it here would ask the
            // database for a column that does not exist.
            if (! $column->isRelational() && ! $column->field->isAggregate()) {
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

        /*
         * The row version an edit is checked against — see Support\Version.
         *
         * Only for a table that can be edited: on a read-only table the column
         * would be selected, sent, and never looked at. On an editable one it
         * is what stops two people silently overwriting each other.
         */
        if ($table->features()->any(Feature::INLINE_EDIT, Feature::BULK_EDIT)) {
            $updatedAt = $query->getModel()->getUpdatedAtColumn();

            if ($query->getModel()->usesTimestamps() && is_string($updatedAt)) {
                $needed[] = $updatedAt;
            }
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

    /**
     * Attach the subquery behind every aggregate column the page will show.
     *
     * One correlated subselect per aggregate, which is what withCount() has
     * always cost — no join, no GROUP BY on the outer query, and no extra row
     * per related record. Only columns are registered here: a filter on an
     * aggregate compiles its own subquery, because a select alias cannot be
     * named in a WHERE clause, and a sort reuses the alias this adds.
     */
    protected function applyAggregates(Builder $query, DynamicTable $table, TableState $state): void
    {
        $fields = [];

        foreach ($this->activeColumns($table, $state) as $column) {
            if ($column->field->isAggregate()) {
                $fields[$column->field->name] = $column->field;
            }
        }

        /*
         * A sort on an aggregate needs its alias to exist even when the column
         * itself is hidden — the reader can turn a column off and keep sorting
         * by it. Keyed by alias, so a column that is both shown and sorted adds
         * one subquery rather than two identically named ones.
         */
        foreach ($state->sort as $entry) {
            $column = $table->columnFor($entry['field']);

            if ($column !== null && $column->field->isAggregate()) {
                $fields[$column->field->name] ??= $column->field;
            }
        }

        foreach ($fields as $field) {
            $relation = (string) $field->aggregateRelation;

            match ($field->aggregate) {
                'count' => $query->withCount($relation),
                'exists' => $query->withExists($relation),
                default => $query->withAggregate($relation, (string) $field->aggregateColumn, (string) $field->aggregate),
            };
        }
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

        /*
         * Pinned rows sort above everything else, including the group.
         *
         * A CASE rather than a UNION or a second query: it keeps one result
         * set, one count and one page, so a pinned row that would have been on
         * page 9 is simply at the top of page 1 and is not also still on page
         * 9. The ids come from the viewer's own session and are bound as
         * parameters.
         */
        $pinned = app(PinMemory::class)->ids($table);

        if ($pinned !== []) {
            $key = $query->getModel()->getQualifiedKeyName();
            $placeholders = implode(', ', array_fill(0, count($pinned), '?'));

            $query->orderByRaw(
                'case when '.$query->getQuery()->getGrammar()->wrap($key).' in ('.$placeholders.') then 0 else 1 end',
                $pinned,
            );
        }

        // Grouping is expressed as a leading sort, so the database does the
        // work and the browser only has to notice where the value changes.
        // Nothing is ever loaded into PHP just to be grouped.
        $group = $state->group === null ? null : $table->columnFor($state->group);

        // An aggregate is excluded along with a computed column, and for the
        // same reason: a heading per distinct subquery result is a grouping of
        // the answer rather than of the data.
        if ($group !== null) {
            if (! $group->isComputed() && ! $group->field->isAggregate()) {
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

            if ($field->isAggregate()) {
                // The alias applyAggregates() added, unqualified: it belongs to
                // the select list, not to a table.
                $query->orderBy($name, $direction);
                $applied++;

                continue;
            }

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

<?php

namespace Shwaeki\DynamicTable\Filters;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Metadata\FieldMetadata;
use Shwaeki\DynamicTable\Metadata\FieldType;
use Shwaeki\DynamicTable\Metadata\MetadataEngine;
use Throwable;

/**
 * Parses untrusted filter payloads into a validated tree, then compiles that
 * tree into Eloquent constraints.
 *
 * Nothing from the request ever reaches SQL as an identifier: field paths are
 * resolved through the metadata engine and rejected when unknown, operators
 * come from a closed enum, and values are bound as parameters.
 */
class FilterEngine
{
    /** Sentinel for "this value cannot be used"; distinct from a legitimate false/null. */
    private static ?object $invalid = null;

    protected static function invalid(): object
    {
        return self::$invalid ??= new \stdClass;
    }

    /** @var list<string> Paths that were dropped during the last parse. */
    protected array $warnings = [];

    public function __construct(protected MetadataEngine $metadata) {}

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $input
     */
    public function parse(mixed $input, DynamicTable $table): FilterGroup
    {
        $this->warnings = [];

        $count = 0;
        $group = $this->parseGroup($input, $table, 0, $count);

        return $group ?? new FilterGroup;
    }

    protected function parseGroup(mixed $input, DynamicTable $table, int $depth, int &$count): ?FilterGroup
    {
        $maxDepth = (int) config('dynamic-table.security.max_filter_depth', 4);
        $maxFilters = (int) config('dynamic-table.security.max_filters', 40);

        if (! is_array($input) || $depth > $maxDepth) {
            return null;
        }

        // A bare list is treated as an AND group.
        $children = $input['conditions'] ?? $input['filters'] ?? (array_is_list($input) ? $input : null);

        if ($children === null) {
            $condition = $this->parseCondition($input, $table);

            return $condition === null ? null : new FilterGroup('and', [$condition]);
        }

        if (! is_array($children)) {
            return null;
        }

        $logic = strtolower((string) ($input['logic'] ?? $input['operator'] ?? 'and'));
        $logic = $logic === 'or' ? 'or' : 'and';
        $negated = (bool) ($input['not'] ?? false);

        $parsed = [];

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            if ($count >= $maxFilters) {
                $this->warnings[] = 'filter_limit_reached';
                break;
            }

            $isGroup = isset($child['conditions']) || isset($child['filters']) || array_is_list($child);

            if ($isGroup) {
                $nested = $this->parseGroup($child, $table, $depth + 1, $count);

                if ($nested !== null && ! $nested->isEmpty()) {
                    $parsed[] = $nested;
                }

                continue;
            }

            $condition = $this->parseCondition($child, $table);

            if ($condition !== null) {
                $parsed[] = $condition;
                $count++;
            }
        }

        return new FilterGroup($logic, $parsed, $negated);
    }

    /** @param array<string, mixed> $input */
    protected function parseCondition(array $input, DynamicTable $table): ?Condition
    {
        $path = $input['field'] ?? $input['column'] ?? null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $column = $table->column(str_replace('.', '__', $path));
        $field = $column !== null
            ? $column->field
            : $this->metadata->resolve($table->modelClass(), $path);

        if ($field === null || ! $field->isFilterable()) {
            $this->warnings[] = $path;

            return null;
        }

        if ($column !== null && ! $column->filterable) {
            $this->warnings[] = $path;

            return null;
        }

        if (! $this->isExposed($table, $field)) {
            $this->warnings[] = $path;

            return null;
        }

        $operator = Operator::tryFrom((string) ($input['operator'] ?? $input['op'] ?? 'equals'));

        if ($operator === null || ! in_array($operator->value, $field->type->operators(), true)) {
            $this->warnings[] = $path;

            return null;
        }

        $raw = $input['value'] ?? null;

        // {"value": {"field": "starts_at"}} — compare two columns instead of a
        // column and a literal.
        if (is_array($raw) && array_key_exists('field', $raw)) {
            $other = $this->comparableField($table, $field, $operator, $raw['field']);

            if ($other === null) {
                $this->warnings[] = $path;

                return null;
            }

            return new Condition($field, $operator, null, $other);
        }

        $value = $this->coerce($field, $operator, $raw, $input['value2'] ?? null);

        if ($value === self::invalid()) {
            $this->warnings[] = $path;

            return null;
        }

        return new Condition($field, $operator, $value);
    }

    /**
     * A field is filterable only when it belongs to this table's exposed
     * surface: an explicit allowlist wins, otherwise the resolved columns
     * plus anything reachable within the table's relation depth.
     */
    protected function isExposed(DynamicTable $table, FieldMetadata $field): bool
    {
        $allowed = $table->allowedColumnPaths();

        if ($allowed !== []) {
            return in_array($field->path, $allowed, true);
        }

        if (in_array($field->path, $table->hiddenColumnPaths(), true)) {
            return false;
        }

        // An aggregate has no relation path — it is a subquery, not a join —
        // but it still reaches through a relationship, which is exactly what a
        // table with "-relations" said it did not want offered.
        $reach = $field->isAggregate() ? 1 : count($field->relationPath);

        return $reach <= $table->relationDepth();
    }

    /**
     * The other side of a field-to-field comparison, or null if it cannot be one.
     *
     * Both sides have to be real columns on the table's own row: comparing a
     * column to one reached through a relation, or to an aggregate, would mean
     * a join or a subquery per row, and neither is something a filter builder
     * should be able to ask for by accident.
     *
     * The types have to belong to the same family, too. "ends_at before name"
     * is not a question, and a database asked it invents an answer rather than
     * refusing.
     */
    protected function comparableField(
        DynamicTable $table,
        FieldMetadata $field,
        Operator $operator,
        mixed $path,
    ): ?FieldMetadata {
        static $comparable = [
            Operator::Equals, Operator::NotEquals,
            Operator::GreaterThan, Operator::GreaterOrEqual,
            Operator::LessThan, Operator::LessOrEqual,
            Operator::Before, Operator::After,
        ];

        if (! is_string($path) || $path === '' || ! in_array($operator, $comparable, true)) {
            return null;
        }

        if ($field->isRelational() || $field->isAggregate() || $field->computed) {
            return null;
        }

        $column = $table->column(str_replace('.', '__', $path));
        $other = $column !== null
            ? $column->field
            : $this->metadata->resolve($table->modelClass(), $path);

        if ($other === null
            || $other->isRelational()
            || $other->isAggregate()
            || $other->computed
            || ! $other->isFilterable()
            || ! $this->isExposed($table, $other)) {
            return null;
        }

        return $this->sameFamily($field->type, $other->type) ? $other : null;
    }

    /** Types that can sensibly be compared to one another. */
    protected function sameFamily(FieldType $left, FieldType $right): bool
    {
        $family = static fn (FieldType $type): string => match ($type) {
            FieldType::Integer, FieldType::Decimal => 'number',
            FieldType::Date, FieldType::DateTime, FieldType::Time => 'time',
            FieldType::Boolean => 'boolean',
            FieldType::Json => 'json',
            default => 'text',
        };

        $left = $family($left);

        return $left === $family($right) && $left !== 'json';
    }

    /**
     * Coerce a raw request value to the field's type.
     *
     * Returns the invalid() sentinel rather than false, because false and null
     * are both legitimate filter values for boolean and nullable fields.
     */
    protected function coerce(FieldMetadata $field, Operator $operator, mixed $value, mixed $second = null): mixed
    {
        $invalid = self::invalid();

        if ($operator->arity() === 0) {
            return null;
        }

        if ($operator->arity() === 2) {
            $pair = is_array($value) ? array_values($value) : [$value, $second];

            if (count($pair) < 2) {
                return $invalid;
            }

            $from = $this->coerceScalar($field, $pair[0]);
            $to = $this->coerceScalar($field, $pair[1]);

            return ($from === $invalid || $to === $invalid) ? $invalid : [$from, $to];
        }

        if ($operator->arity() === -1) {
            $values = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : [$value]);
            $coerced = [];

            foreach (array_slice($values, 0, 500) as $item) {
                $item = $this->coerceScalar($field, $item);

                if ($item !== $invalid) {
                    $coerced[] = $item;
                }
            }

            return $coerced === [] ? $invalid : $coerced;
        }

        if ($operator === Operator::LastNDays || $operator === Operator::NextNDays) {
            return is_numeric($value) ? max(0, min(3650, (int) $value)) : $invalid;
        }

        return $this->coerceScalar($field, $value);
    }

    protected function coerceScalar(FieldMetadata $field, mixed $value): mixed
    {
        $invalid = self::invalid();

        if (is_array($value) || is_object($value)) {
            return $invalid;
        }

        if ($value === null || $value === '') {
            return $field->nullable ? null : $invalid;
        }

        return match ($field->type) {
            FieldType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $invalid,
            FieldType::Integer => is_numeric($value) ? (int) $value : $invalid,
            FieldType::Decimal => is_numeric($value) ? (float) $value : $invalid,
            FieldType::Date, FieldType::DateTime => $this->coerceDate($value),
            FieldType::Enum => $this->coerceEnum($field, $value),
            default => (string) $value,
        };
    }

    protected function coerceDate(mixed $value): string|object
    {
        try {
            return CarbonImmutable::parse((string) $value)->toDateTimeString();
        } catch (Throwable) {
            return self::invalid();
        }
    }

    protected function coerceEnum(FieldMetadata $field, mixed $value): mixed
    {
        if ($field->options === []) {
            return (string) $value;
        }

        foreach ($field->options as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['value'];
            }
        }

        return self::invalid();
    }

    /* ------------------------------------------------------------------ */
    /* Compilation */
    /* ------------------------------------------------------------------ */

    /**
     * @param  EloquentBuilder<Model>  $query
     */
    public function apply(EloquentBuilder $query, FilterGroup $group): void
    {
        if ($group->isEmpty()) {
            return;
        }

        $query->where(function (EloquentBuilder $nested) use ($group): void {
            $this->applyGroup($nested, $group);
        });
    }

    protected function applyGroup(EloquentBuilder $query, FilterGroup $group): void
    {
        $first = true;

        foreach ($group->children as $child) {
            $boolean = ($first || $group->logic === 'and') ? 'and' : 'or';

            $callback = function (EloquentBuilder $nested) use ($child): void {
                if ($child instanceof FilterGroup) {
                    $this->applyGroup($nested, $child);
                } else {
                    $this->applyCondition($nested, $child);
                }
            };

            if ($boolean === 'or') {
                $query->orWhere($callback);
            } else {
                $query->where($callback);
            }

            $first = false;
        }
    }

    protected function applyCondition(EloquentBuilder $query, Condition $condition): void
    {
        $field = $condition->field;

        if ($condition->valueField !== null) {
            // Both sides are columns on this table's own row, checked when the
            // condition was parsed, so whereColumn needs no subquery and no
            // join — and neither name comes from the request.
            $query->whereColumn(
                $query->qualifyColumn((string) ($field->column ?? $field->name)),
                (string) $this->comparison($condition->operator),
                $query->qualifyColumn((string) ($condition->valueField->column ?? $condition->valueField->name)),
            );

            return;
        }

        if ($field->isAggregate()) {
            $this->applyAggregate($query, $condition);

            return;
        }

        if (! $field->isRelational()) {
            $this->applyLeaf($query, $query->qualifyColumn((string) ($field->column ?? $field->name)), $condition);

            return;
        }

        $relation = (string) $field->relationKey();
        $column = (string) ($field->column ?? $field->name);
        $operator = $condition->operator;

        if ($operator === Operator::IsEmpty) {
            $query->where(function (EloquentBuilder $nested) use ($relation, $column): void {
                $nested->whereDoesntHave($relation)
                    ->orWhereHas($relation, function (EloquentBuilder $related) use ($column): void {
                        $related->whereNull($related->qualifyColumn($column));
                    });
            });

            return;
        }

        if ($operator === Operator::IsNotEmpty) {
            $query->whereHas($relation, function (EloquentBuilder $related) use ($column): void {
                $related->whereNotNull($related->qualifyColumn($column));
            });

            return;
        }

        if ($operator->isNegative()) {
            $positive = new Condition($field, $this->invert($operator), $condition->value);

            $query->whereDoesntHave($relation, function (EloquentBuilder $related) use ($column, $positive): void {
                $this->applyLeaf($related, $related->qualifyColumn($column), $positive);
            });

            return;
        }

        $query->whereHas($relation, function (EloquentBuilder $related) use ($column, $condition): void {
            $this->applyLeaf($related, $related->qualifyColumn($column), $condition);
        });
    }

    /**
     * A filter on an aggregate over a plural relation.
     *
     * Existence and count go through whereHas(), because Eloquent already
     * compiles those to the EXISTS or correlated-count form a database can use
     * an index for — and "= 0" or "< 1" it turns into a NOT EXISTS by itself.
     *
     * The value aggregates have no such shorthand, so the subquery the select
     * would have used is repeated inside the WHERE. It has to be repeated: a
     * select alias cannot be named in a WHERE clause in any of the databases
     * this package supports, and lifting the comparison into HAVING would mean
     * grouping the outer query, which changes what a row is.
     */
    protected function applyAggregate(EloquentBuilder $query, Condition $condition): void
    {
        $field = $condition->field;
        $relation = (string) $field->aggregateRelation;
        $operator = $condition->operator;
        $value = $condition->value;

        if ($operator === Operator::IsEmpty || $operator === Operator::IsNotEmpty) {
            // "Empty" on an aggregate means the relation has no rows: a min
            // over nothing is null, and a sum over nothing is zero, but both
            // describe the same customer with no orders.
            $operator === Operator::IsEmpty
                ? $query->whereDoesntHave($relation)
                : $query->whereHas($relation);

            return;
        }

        if ($field->aggregate === 'exists') {
            $wanted = (bool) $value;

            if ($operator === Operator::NotEquals) {
                $wanted = ! $wanted;
            }

            $wanted ? $query->whereHas($relation) : $query->whereDoesntHave($relation);

            return;
        }

        if ($operator === Operator::In || $operator === Operator::NotIn) {
            $sub = $this->aggregateSubquery($query, $field);
            $values = array_values((array) $value);

            if ($sub === null || $values === []) {
                return;
            }

            $query->getQuery()->whereRaw(
                '('.$sub->toSql().')'.($operator === Operator::NotIn ? ' not in ' : ' in ')
                    .'('.implode(', ', array_fill(0, count($values), '?')).')',
                array_merge($sub->getBindings(), $values),
            );

            return;
        }

        $comparison = $this->comparison($operator);

        if ($comparison === null) {
            return;
        }

        $ranged = $operator === Operator::Between || $operator === Operator::NotBetween;

        [$low, $high] = $ranged ? $this->range($value, $field->type) : [null, null];

        if ($field->aggregate === 'count' && ! $ranged) {
            $query->whereHas($relation, null, $comparison, (int) $value);

            return;
        }

        if ($field->aggregate === 'count') {
            // Between as its two halves. Two subqueries rather than one, and
            // the database optimises them the same way it optimises any pair of
            // EXISTS clauses.
            $operator === Operator::Between
                ? $query->whereHas($relation, null, '>=', (int) $low)->whereHas($relation, null, '<=', (int) $high)
                : $query->where(function (EloquentBuilder $nested) use ($relation, $low, $high): void {
                    $nested->whereHas($relation, null, '<', (int) $low)
                        ->orWhereHas($relation, null, '>', (int) $high);
                });

            return;
        }

        $sub = $this->aggregateSubquery($query, $field);

        if ($sub === null) {
            return;
        }

        $sql = '('.$sub->toSql().')';

        /*
         * "? + 0" rather than "?", for numbers only.
         *
         * PDO has no float parameter type, so Laravel binds a float as a
         * string. Compared against a plain column that costs nothing — the
         * column's own affinity converts it — but a subquery has no affinity,
         * and SQLite then sorts every text value above every number: "spend
         * over 100000" matched nobody and "under 100" matched everybody. The
         * arithmetic forces the parameter into numeric context on SQLite,
         * MySQL and Postgres alike.
         *
         * A min or max over a date or a string is left alone: there both sides
         * are text already, and the addition would be nonsense.
         */
        $numeric = in_array($field->type, [FieldType::Integer, FieldType::Decimal], true);
        $bind = $numeric ? '(? + 0)' : '?';

        if ($low !== null) {
            $query->getQuery()->whereRaw(
                $operator === Operator::Between
                    ? $sql.' >= '.$bind.' and '.$sql.' <= '.$bind
                    : '('.$sql.' < '.$bind.' or '.$sql.' > '.$bind.')',
                array_merge($sub->getBindings(), [$low], $sub->getBindings(), [$high]),
            );

            return;
        }

        $query->getQuery()->whereRaw($sql.' '.$comparison.' '.$bind, array_merge($sub->getBindings(), [$value]));
    }

    /**
     * The aggregate as a standalone correlated subquery.
     *
     * Built the way Eloquent builds it for withAggregate(), so a filter and a
     * column on the same aggregate always mean the same number — including the
     * coalesce, without which "total spend under 100" would silently exclude
     * every customer who has never ordered.
     */
    protected function aggregateSubquery(EloquentBuilder $query, FieldMetadata $field): ?QueryBuilder
    {
        $name = (string) $field->aggregateRelation;

        try {
            /*
             * Without constraints, exactly as withAggregate() builds it.
             *
             * A relation read off a model instance carries that instance's own
             * key as a where clause, and the instance here has no key — so the
             * subquery came back constrained to "customer_id is null" and every
             * row's total was zero. Under noConstraints the correlation is
             * added by getRelationExistenceQuery() against the outer query,
             * which is the whole point of it.
             */
            $relation = Relation::noConstraints(
                static fn () => $query->getModel()->newInstance()->{$name}()
            );
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof Relation) {
            return null;
        }

        $related = $relation->getRelated();
        $function = (string) $field->aggregate;

        // count has no column of its own, and counting rows is the point.
        $wrapped = $function === 'count'
            ? 'count(*)'
            : $function.'('.$query->getQuery()->getGrammar()->wrap(
                $related->qualifyColumn((string) $field->aggregateColumn)
            ).')';

        // min and max over no rows are genuinely nothing; a sum, a count or an
        // average of no rows is zero, which is the answer people expect and the
        // one withAggregate() gives.
        if (in_array($function, ['sum', 'avg', 'count'], true)) {
            $wrapped = 'coalesce('.$wrapped.', 0)';
        }

        $sub = $relation
            ->getRelationExistenceQuery($related->newQuery(), $query, new Expression($wrapped))
            ->setBindings([], 'select')
            ->mergeConstraintsFrom($relation->getQuery())
            ->toBase();

        // An ORDER BY inside a scalar subquery is dead weight the grammar still
        // has to write, and its bindings would land in the wrong section.
        $sub->orders = null;
        $sub->setBindings([], 'order');

        return $sub;
    }

    /** The SQL comparison an operator makes on a single value, or null if it makes none. */
    protected function comparison(Operator $operator): ?string
    {
        return match ($operator) {
            Operator::Equals => '=',
            Operator::NotEquals => '!=',
            Operator::GreaterThan => '>',
            Operator::GreaterOrEqual => '>=',
            Operator::LessThan => '<',
            Operator::LessOrEqual => '<=',
            Operator::Before => '<',
            Operator::After => '>',
            Operator::Between, Operator::NotBetween => 'between',
            default => null,
        };
    }

    protected function invert(Operator $operator): Operator
    {
        return match ($operator) {
            Operator::NotEquals => Operator::Equals,
            Operator::NotContains => Operator::Contains,
            Operator::NotIn => Operator::In,
            Operator::NotBetween => Operator::Between,
            default => $operator,
        };
    }

    /**
     * @param  EloquentBuilder<Model>  $query
     */
    protected function applyLeaf(EloquentBuilder $query, string $column, Condition $condition): void
    {
        $operator = $condition->operator;
        $value = $condition->value;
        $type = $condition->field->type;

        if ($operator->isRelativeDate() || $operator === Operator::LastNDays || $operator === Operator::NextNDays) {
            [$from, $to] = $this->dateRange($operator, is_int($value) ? $value : 0);
            $query->whereBetween($column, [$from, $to]);

            return;
        }

        match ($operator) {
            Operator::IsEmpty => $type->isTextual()
                ? $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '=', ''))
                : $query->whereNull($column),
            Operator::IsNotEmpty => $type->isTextual()
                ? $query->whereNotNull($column)->where($column, '!=', '')
                : $query->whereNotNull($column),
            Operator::Equals => $this->equals($query, $column, $value, $type),
            Operator::NotEquals => $this->notEquals($query, $column, $value, $type),
            Operator::Contains => $query->where($column, 'like', '%'.$this->escapeLike((string) $value).'%'),
            Operator::NotContains => $query->where(function ($q) use ($column, $value): void {
                $q->whereNull($column)->orWhere($column, 'not like', '%'.$this->escapeLike((string) $value).'%');
            }),
            Operator::StartsWith => $query->where($column, 'like', $this->escapeLike((string) $value).'%'),
            Operator::EndsWith => $query->where($column, 'like', '%'.$this->escapeLike((string) $value)),
            Operator::GreaterThan, Operator::After => $query->where($column, '>', $value),
            Operator::GreaterOrEqual => $query->where($column, '>=', $value),
            Operator::LessThan, Operator::Before => $query->where($column, '<', $value),
            Operator::LessOrEqual => $query->where($column, '<=', $value),
            Operator::Between => $query->whereBetween($column, $this->range($value, $type)),
            Operator::NotBetween => $query->whereNotBetween($column, $this->range($value, $type)),
            Operator::In => $query->whereIn($column, (array) $value),
            Operator::NotIn => $query->where(function ($q) use ($column, $value): void {
                $q->whereNull($column)->orWhereNotIn($column, (array) $value);
            }),
            default => null,
        };
    }

    protected function equals(EloquentBuilder $query, string $column, mixed $value, FieldType $type): void
    {
        if ($value === null) {
            $query->whereNull($column);

            return;
        }

        if ($type === FieldType::DateTime && $this->isDateOnly($value)) {
            $query->whereDate($column, '=', substr((string) $value, 0, 10));

            return;
        }

        $query->where($column, '=', $value);
    }

    protected function notEquals(EloquentBuilder $query, string $column, mixed $value, FieldType $type): void
    {
        if ($value === null) {
            $query->whereNotNull($column);

            return;
        }

        $query->where(function ($q) use ($column, $value, $type): void {
            $q->whereNull($column);

            if ($type === FieldType::DateTime && $this->isDateOnly($value)) {
                $q->orWhereDate($column, '!=', substr((string) $value, 0, 10));
            } else {
                $q->orWhere($column, '!=', $value);
            }
        });
    }

    protected function isDateOnly(mixed $value): bool
    {
        return is_string($value) && str_ends_with($value, '00:00:00');
    }

    /** @return array{0: mixed, 1: mixed} */
    protected function range(mixed $value, FieldType $type): array
    {
        $pair = array_values((array) $value);
        $from = $pair[0] ?? null;
        $to = $pair[1] ?? null;

        // A date-only upper bound should include the whole day.
        if ($type === FieldType::DateTime && is_string($to) && $this->isDateOnly($to)) {
            $to = substr($to, 0, 10).' 23:59:59';
        }

        return [$from, $to];
    }

    /** @return array{0: string, 1: string} */
    protected function dateRange(Operator $operator, int $days = 0): array
    {
        $now = CarbonImmutable::now();

        [$from, $to] = match ($operator) {
            Operator::Today => [$now->startOfDay(), $now->endOfDay()],
            Operator::Yesterday => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            Operator::Tomorrow => [$now->addDay()->startOfDay(), $now->addDay()->endOfDay()],
            Operator::ThisWeek => [$now->startOfWeek(), $now->endOfWeek()],
            Operator::LastWeek => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            Operator::NextWeek => [$now->addWeek()->startOfWeek(), $now->addWeek()->endOfWeek()],
            Operator::ThisMonth => [$now->startOfMonth(), $now->endOfMonth()],
            Operator::LastMonth => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            Operator::NextMonth => [$now->addMonth()->startOfMonth(), $now->addMonth()->endOfMonth()],
            Operator::ThisYear => [$now->startOfYear(), $now->endOfYear()],
            Operator::LastYear => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            Operator::LastNDays => [$now->subDays($days)->startOfDay(), $now->endOfDay()],
            Operator::NextNDays => [$now->startOfDay(), $now->addDays($days)->endOfDay()],
            default => [$now->startOfDay(), $now->endOfDay()],
        };

        return [$from->toDateTimeString(), $to->toDateTimeString()];
    }

    /** Escape LIKE wildcards so user input cannot widen the match. */
    public function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}

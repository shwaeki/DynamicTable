<?php

namespace Shwaeki\DynamicTable\Filters;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
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

        $value = $this->coerce($field, $operator, $input['value'] ?? null, $input['value2'] ?? null);

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

        return count($field->relationPath) <= $table->relationDepth();
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

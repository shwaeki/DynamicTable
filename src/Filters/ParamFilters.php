<?php

namespace Shwaeki\DynamicTable\Filters;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Parameters bound straight to the query.
 *
 * Almost every table with controls of its own ends up writing the same
 * `when($this->param('x'), fn ($q, $v) => $q->where(...))` chain. Declaring it
 * says the same thing in one line each:
 *
 *     protected array $paramFilters = [
 *         'status',                                                 // where('status', $value)
 *         'category' => 'company_category_id',                      // parameter name is not the column
 *         'q' => ['column' => 'name', 'operator' => 'contains'],
 *         'area' => ['column' => 'companyArea.slug'],               // through a relation
 *         'created_period' => ['column' => 'created_at', 'operator' => 'period'],
 *         'agent' => fn (Builder $q, $value) => $q->whereHas(...),  // anything else
 *     ];
 *
 * Every name here is a declared parameter, so $params need not repeat them, and
 * a filter whose parameter did not arrive is simply not applied.
 *
 * Column names come from the table class, never from the request: only the
 * value is user input, and it is always bound.
 */
final class ParamFilters
{
    /** Operator names accepted in a declaration, mapped to SQL comparisons. */
    private const COMPARISONS = [
        'equals' => '=', '=' => '=',
        'not_equals' => '!=', '!=' => '!=', '<>' => '!=',
        'greater_than' => '>', '>' => '>',
        'greater_or_equal' => '>=', '>=' => '>=',
        'less_than' => '<', '<' => '<',
        'less_or_equal' => '<=', '<=' => '<=',
        'after' => '>', 'before' => '<',
    ];

    /** Shorthand periods: a window reaching back from now, in days. */
    private const WINDOWS = ['day' => 1, 'week' => 7, 'month' => 30, 'quarter' => 90, 'year' => 365];

    /**
     * Every parameter a table's filters read, so they are declared for free.
     *
     * @param  array<int|string, mixed>  $filters
     * @return list<string>
     */
    public static function parameters(array $filters): array
    {
        $names = [];

        foreach ($filters as $key => $spec) {
            $name = is_int($key) ? (string) $spec : (string) $key;
            $names[] = $name;

            if (is_array($spec) && self::operator($spec) === 'period') {
                $names[] = self::companion($spec, $name, 'from');
                $names[] = self::companion($spec, $name, 'to');
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Apply a table's declared filters to its query.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function apply(DynamicTable $table, Builder $query): Builder
    {
        foreach ($table->paramFilters() as $key => $spec) {
            $name = is_int($key) ? (string) $spec : (string) $key;
            $value = $table->param($name);

            if ($spec instanceof Closure) {
                if ($value !== null) {
                    $spec($query, $value, $table);
                }

                continue;
            }

            $spec = is_array($spec) ? $spec : ['column' => is_int($key) ? $name : (string) $spec];
            $operator = self::operator($spec);
            $column = (string) ($spec['column'] ?? $name);

            if ($operator === 'period') {
                self::period($query, $column, $table, $spec, $name);

                continue;
            }

            if ($value === null) {
                continue;
            }

            self::constrain($query, $column, $operator, $value);
        }

        return $query;
    }

    /**
     * One constraint, on a column of this table or of one of its relations.
     *
     * A dotted column is a relation path — `companyArea.slug` — and becomes a
     * whereHas, which is what the filter builder does with one too.
     *
     * @param  Builder<Model>  $query
     */
    private static function constrain(Builder $query, string $column, string $operator, mixed $value): void
    {
        if (str_contains($column, '.')) {
            $segments = explode('.', $column);
            $attribute = array_pop($segments);

            $query->whereHas(implode('.', $segments), function (Builder $related) use ($attribute, $operator, $value): void {
                self::constrain($related, $attribute, $operator, $value);
            });

            return;
        }

        match ($operator) {
            'in' => $query->whereIn($column, (array) $value),
            'not_in' => $query->whereNotIn($column, (array) $value),
            'contains' => $query->where($column, 'like', '%'.self::escapeLike((string) $value).'%'),
            'starts_with' => $query->where($column, 'like', self::escapeLike((string) $value).'%'),
            'ends_with' => $query->where($column, 'like', '%'.self::escapeLike((string) $value)),
            'date' => $query->whereDate($column, '=', $value),
            'between' => self::between($query, $column, $value),
            default => is_array($value)
                ? $query->whereIn($column, $value)
                : $query->where($column, self::COMPARISONS[$operator] ?? '=', $value),
        };
    }

    /**
     * A date window from one parameter.
     *
     * The value is either a shorthand window (day, week, month, quarter,
     * year), any relative operator the filter builder knows (today, this_month,
     * last_year, last_n_days:30), or "custom" — which reads the two companion
     * parameters, `<name>_from` and `<name>_to` by default.
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $spec
     */
    private static function period(Builder $query, string $column, DynamicTable $table, array $spec, string $name): void
    {
        $period = $table->param($name);

        if ($period === null || $period === 'custom' || $period === 'range') {
            // "Custom" is the usual name for the two date inputs beside the
            // picker; they also work on their own, with no picker at all.
            $from = $table->param(self::companion($spec, $name, 'from'));
            $to = $table->param(self::companion($spec, $name, 'to'));

            if ($from !== null) {
                $query->where($column, '>=', CarbonImmutable::parse((string) $from)->startOfDay());
            }

            if ($to !== null) {
                $query->where($column, '<=', CarbonImmutable::parse((string) $to)->endOfDay());
            }

            return;
        }

        [$value, $argument] = array_pad(explode(':', (string) $period, 2), 2, null);

        if (isset(self::WINDOWS[$value])) {
            $query->where($column, '>=', CarbonImmutable::now()->subDays(self::WINDOWS[$value]));

            return;
        }

        $operator = Operator::tryFrom((string) $value);

        if ($operator === null) {
            return;
        }

        $query->whereBetween($column, self::range($operator, (int) ($argument ?? 0)));
    }

    /** @return array{0: string, 1: string} */
    private static function range(Operator $operator, int $days): array
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

    /** @param array<string, mixed> $spec */
    private static function operator(array $spec): string
    {
        return strtolower((string) ($spec['operator'] ?? $spec['type'] ?? 'equals'));
    }

    /**
     * The parameter holding one end of a custom range.
     *
     * `created_period` pairs with `created_from` and `created_to`, because a
     * picker named `<thing>_period` is the shape everyone writes; anything else
     * pairs with `<name>_from` and `<name>_to`. Either can be named outright in
     * the declaration.
     *
     * @param  array<string, mixed>  $spec
     */
    private static function companion(array $spec, string $name, string $end): string
    {
        if (isset($spec[$end])) {
            return (string) $spec[$end];
        }

        $base = str_ends_with($name, '_period') ? substr($name, 0, -7) : $name;

        return $base.'_'.$end;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private static function between(Builder $query, string $column, mixed $value): void
    {
        $pair = array_values((array) $value);

        if (count($pair) === 2) {
            $query->whereBetween($column, [$pair[0], $pair[1]]);
        }
    }

    /** Escape LIKE wildcards so a value cannot widen its own match. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}

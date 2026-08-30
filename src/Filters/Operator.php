<?php

namespace Shwaeki\DynamicTable\Filters;

enum Operator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case GreaterThan = 'greater_than';
    case GreaterOrEqual = 'greater_or_equal';
    case LessThan = 'less_than';
    case LessOrEqual = 'less_or_equal';
    case Between = 'between';
    case NotBetween = 'not_between';
    case In = 'in';
    case NotIn = 'not_in';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'is_not_empty';
    case Before = 'before';
    case After = 'after';
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Tomorrow = 'tomorrow';
    case ThisWeek = 'this_week';
    case LastWeek = 'last_week';
    case NextWeek = 'next_week';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case NextMonth = 'next_month';
    case ThisYear = 'this_year';
    case LastYear = 'last_year';
    case LastNDays = 'last_n_days';
    case NextNDays = 'next_n_days';

    /** How many values the operator consumes: 0, 1, 2, or -1 for a list. */
    public function arity(): int
    {
        return match ($this) {
            self::IsEmpty, self::IsNotEmpty, self::Today, self::Yesterday, self::Tomorrow,
            self::ThisWeek, self::LastWeek, self::NextWeek, self::ThisMonth, self::LastMonth,
            self::NextMonth, self::ThisYear, self::LastYear => 0,
            self::Between, self::NotBetween => 2,
            self::In, self::NotIn => -1,
            default => 1,
        };
    }

    /** Relative date operators need no value and resolve to a range at query time. */
    public function isRelativeDate(): bool
    {
        return in_array($this, [
            self::Today, self::Yesterday, self::Tomorrow,
            self::ThisWeek, self::LastWeek, self::NextWeek,
            self::ThisMonth, self::LastMonth, self::NextMonth,
            self::ThisYear, self::LastYear,
        ], true);
    }

    public function isNullCheck(): bool
    {
        return $this === self::IsEmpty || $this === self::IsNotEmpty;
    }

    /** True when the operator matches records the positive form would exclude. */
    public function isNegative(): bool
    {
        return in_array($this, [
            self::NotEquals, self::NotContains, self::NotIn, self::NotBetween, self::IsEmpty,
        ], true);
    }

    public function label(): string
    {
        return (string) __('dynamic-table::operators.'.$this->value);
    }
}

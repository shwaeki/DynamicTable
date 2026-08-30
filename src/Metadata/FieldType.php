<?php

namespace Shwaeki\DynamicTable\Metadata;

enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';
    case Enum = 'enum';
    case Json = 'json';
    case Uuid = 'uuid';
    case Email = 'email';
    case Url = 'url';
    case Image = 'image';
    case Relation = 'relation';
    case Unknown = 'unknown';

    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Decimal;
    }

    public function isTemporal(): bool
    {
        return $this === self::Date || $this === self::DateTime || $this === self::Time;
    }

    public function isTextual(): bool
    {
        return in_array($this, [self::String, self::Text, self::Email, self::Url, self::Uuid, self::Image], true);
    }

    /** Whether a value of this type can safely take part in a SQL LIKE search. */
    public function isSearchable(): bool
    {
        return $this === self::String || $this === self::Text || $this === self::Email || $this === self::Url;
    }

    /**
     * Operators that make sense for this type, in display order.
     *
     * @return list<string>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Boolean => ['equals', 'is_empty', 'is_not_empty'],
            self::Integer, self::Decimal => [
                'equals', 'not_equals', 'greater_than', 'greater_or_equal',
                'less_than', 'less_or_equal', 'between', 'in', 'is_empty', 'is_not_empty',
            ],
            self::Date, self::DateTime => [
                'equals', 'not_equals', 'before', 'after', 'between',
                'today', 'yesterday', 'this_week', 'this_month', 'this_year',
                'last_week', 'last_month', 'last_year', 'next_week', 'next_month',
                'last_n_days', 'next_n_days', 'is_empty', 'is_not_empty',
            ],
            self::Time => ['equals', 'not_equals', 'before', 'after', 'between', 'is_empty', 'is_not_empty'],
            self::Enum => ['equals', 'not_equals', 'in', 'not_in', 'is_empty', 'is_not_empty'],
            self::Json => ['contains', 'not_contains', 'is_empty', 'is_not_empty'],
            self::Relation => ['equals', 'not_equals', 'in', 'not_in', 'is_empty', 'is_not_empty'],
            self::Uuid => ['equals', 'not_equals', 'in', 'is_empty', 'is_not_empty'],
            default => [
                'contains', 'not_contains', 'equals', 'not_equals',
                'starts_with', 'ends_with', 'in', 'is_empty', 'is_not_empty',
            ],
        };
    }

    /** The client-side input widget used to collect a filter value. */
    public function input(): string
    {
        return match ($this) {
            self::Boolean => 'boolean',
            self::Integer, self::Decimal => 'number',
            self::Date => 'date',
            self::DateTime => 'datetime',
            self::Time => 'time',
            self::Enum => 'select',
            self::Relation => 'remote-select',
            default => 'text',
        };
    }
}

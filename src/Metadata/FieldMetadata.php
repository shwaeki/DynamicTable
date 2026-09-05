<?php

namespace Shwaeki\DynamicTable\Metadata;

/**
 * A single addressable field: either a real database column on the root model,
 * a column reached through a relationship chain, or a computed accessor.
 */
final class FieldMetadata
{
    /**
     * @param  list<string>  $relationPath  e.g. ['department', 'manager']
     * @param  array<int, array{value: string|int, label: string}>  $options
     */
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $label,
        public readonly FieldType $type,
        public readonly bool $nullable = true,
        public readonly bool $computed = false,
        public readonly array $relationPath = [],
        public readonly ?string $relationType = null,
        public readonly ?string $relatedModel = null,
        public readonly array $options = [],
        public readonly ?string $enumClass = null,
        public readonly ?string $column = null,
        public readonly ?int $length = null,
        public readonly bool $primary = false,
        public readonly bool $indexed = false,
        /**
         * An aggregate over a plural relation: count, exists, sum, avg, min or
         * max. Such a field has no column of its own — it is a subquery the
         * database evaluates per row — so it is deliberately *not* relational:
         * nothing eager loads it and nothing joins for it.
         */
        public readonly ?string $aggregate = null,
        /** The plural relation the aggregate runs over, e.g. "orders". */
        public readonly ?string $aggregateRelation = null,
        /** The column being aggregated. Null for count and exists. */
        public readonly ?string $aggregateColumn = null,
    ) {}

    public function isRelational(): bool
    {
        return $this->relationPath !== [];
    }

    public function isAggregate(): bool
    {
        return $this->aggregate !== null;
    }

    /**
     * Can this field participate in SQL WHERE/ORDER BY at all?
     * Computed accessors cannot — they only exist after hydration.
     */
    public function isQueryable(): bool
    {
        return ! $this->computed;
    }

    public function isSortable(): bool
    {
        return $this->isQueryable() && $this->type !== FieldType::Json;
    }

    public function isSearchable(): bool
    {
        // Never an aggregate. Search compiles to a WHERE on the column, and a
        // subquery alias cannot be named in a WHERE clause — Postgres refuses
        // it outright and MySQL only tolerates it in HAVING. "Customers with
        // more than five orders" is a filter, not a search term.
        return $this->isQueryable() && ! $this->isAggregate() && $this->type->isSearchable();
    }

    public function isFilterable(): bool
    {
        return $this->isQueryable();
    }

    /** The relationship prefix, e.g. "department.manager" or null for a root field. */
    public function relationKey(): ?string
    {
        return $this->relationPath === [] ? null : implode('.', $this->relationPath);
    }

    /** The group heading used by the filter builder and column picker. */
    public function group(): ?string
    {
        return $this->relationKey();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'label' => $this->label,
            'type' => $this->type->value,
            'input' => $this->type->input(),
            'operators' => $this->type->operators(),
            'nullable' => $this->nullable,
            'computed' => $this->computed,
            'sortable' => $this->isSortable(),
            'searchable' => $this->isSearchable(),
            'filterable' => $this->isFilterable(),
            'relation' => $this->relationKey(),
            'relationType' => $this->relationType,
            'aggregate' => $this->aggregate,
            'options' => $this->options,
        ];
    }

    public function with(FieldType $type): self
    {
        return new self(
            $this->path, $this->name, $this->label, $type, $this->nullable,
            $this->computed, $this->relationPath, $this->relationType, $this->relatedModel,
            $this->options, $this->enumClass, $this->column, $this->length,
            $this->primary, $this->indexed,
            $this->aggregate, $this->aggregateRelation, $this->aggregateColumn,
        );
    }
}

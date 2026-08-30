<?php

namespace Shwaeki\DynamicTable\Filters;

use Shwaeki\DynamicTable\Metadata\FieldMetadata;

/** A single validated field/operator/value triple. */
final class Condition
{
    public function __construct(
        public readonly FieldMetadata $field,
        public readonly Operator $operator,
        public readonly mixed $value = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'field' => $this->field->path,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ], static fn (mixed $v): bool => $v !== null);
    }
}

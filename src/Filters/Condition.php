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
        /**
         * The field this condition compares against, when it compares against
         * a field rather than a value: "ends_at before starts_at". Null for
         * the ordinary case, which is nearly all of them.
         */
        public readonly ?FieldMetadata $valueField = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'field' => $this->field->path,
            'operator' => $this->operator->value,
            // Round-trips as it arrived, so a saved view holding a field
            // comparison reopens as one instead of as a filter on the literal
            // string "starts_at".
            'value' => $this->valueField !== null
                ? ['field' => $this->valueField->path]
                : $this->value,
        ], static fn (mixed $v): bool => $v !== null);
    }
}

<?php

namespace Shwaeki\DynamicTable\Filters;

/** A boolean group of conditions and nested groups. */
final class FilterGroup
{
    /** @param list<Condition|FilterGroup> $children */
    public function __construct(
        public readonly string $logic = 'and',
        public readonly array $children = [],
        public readonly bool $negated = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->children === [];
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this->children as $child) {
            $count += $child instanceof self ? $child->count() : 1;
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'logic' => $this->logic,
            'not' => $this->negated,
            'conditions' => array_map(
                static fn (Condition|FilterGroup $child): array => $child->toArray(),
                $this->children,
            ),
        ], static fn (mixed $v): bool => $v !== false);
    }
}

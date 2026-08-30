<?php

namespace Shwaeki\DynamicTable\Events;

class BulkActionExecuted
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public readonly string $tableKey,
        public readonly string $action,
        public readonly int $affected,
        public readonly array $input = [],
    ) {}
}

<?php

namespace Shwaeki\DynamicTable\Events;

use Illuminate\Database\Eloquent\Model;

class RowUpdated
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public readonly string $tableKey,
        public readonly Model $record,
        public readonly array $changes,
    ) {}
}

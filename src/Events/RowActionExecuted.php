<?php

namespace Shwaeki\DynamicTable\Events;

use Illuminate\Database\Eloquent\Model;

class RowActionExecuted
{
    public function __construct(
        public readonly string $tableKey,
        public readonly string $action,
        public readonly Model $record,
    ) {}
}

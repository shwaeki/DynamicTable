<?php

namespace Shwaeki\DynamicTable\Events;

use Illuminate\Database\Eloquent\Model;

class RowCreated
{
    public function __construct(
        public readonly string $tableKey,
        public readonly Model $record,
    ) {}
}

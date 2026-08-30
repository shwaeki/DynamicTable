<?php

namespace Shwaeki\DynamicTable\Events;

use Shwaeki\DynamicTable\Models\DynamicTableView;

class ViewCreated
{
    public function __construct(
        public readonly string $tableKey,
        public readonly DynamicTableView $view,
    ) {}
}

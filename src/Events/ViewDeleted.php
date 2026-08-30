<?php

namespace Shwaeki\DynamicTable\Events;

class ViewDeleted
{
    public function __construct(
        public readonly string $tableKey,
        public readonly string $viewId,
    ) {}
}

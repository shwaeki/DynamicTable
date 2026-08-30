<?php

namespace Shwaeki\DynamicTable\Events;

/**
 * Base class for export/import lifecycle events. Listeners can type-hint the
 * concrete subclass, or this class to observe every transfer.
 */
abstract class TransferEvent
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $tableKey,
        public readonly string $jobId,
        public readonly array $context = [],
    ) {}
}

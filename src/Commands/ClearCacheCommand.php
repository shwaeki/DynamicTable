<?php

namespace Shwaeki\DynamicTable\Commands;

use Illuminate\Console\Command;
use Shwaeki\DynamicTable\Metadata\MetadataEngine;
use Shwaeki\DynamicTable\Support\TableRegistry;

class ClearCacheCommand extends Command
{
    protected $signature = 'dynamic-table:clear';

    protected $description = 'Forget cached DynamicTable metadata and the table registry';

    public function handle(MetadataEngine $metadata, TableRegistry $registry): int
    {
        $registry->flush();
        $metadata->flush();

        $this->components->info('DynamicTable metadata cache cleared.');

        return self::SUCCESS;
    }
}

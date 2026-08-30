<?php

namespace Shwaeki\DynamicTable\Modules\Import;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shwaeki\DynamicTable\Support\TableRegistry;
use Shwaeki\DynamicTable\Support\TransferProgress;
use Throwable;

class ImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    /**
     * @param  array<int, string|null>  $mapping
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $tableKey,
        public readonly string $path,
        public readonly array $mapping,
        public readonly string $mode,
        public readonly array $options,
        public readonly string $progressId,
    ) {
        $this->onQueue(config('dynamic-table.excel.queue'));
    }

    public function handle(TableRegistry $registry, ImportManager $manager): void
    {
        try {
            $table = $registry->resolve($this->tableKey);

            TransferProgress::update($this->progressId, ['status' => 'running']);

            $summary = $manager->run(
                $table,
                $this->path,
                $this->mapping,
                $this->mode,
                $this->options,
                $this->progressId,
            );

            TransferProgress::finish($this->progressId, $summary);
        } catch (Throwable $exception) {
            TransferProgress::fail($this->progressId, $exception->getMessage());

            throw $exception;
        } finally {
            @unlink($this->path);
        }
    }
}

<?php

namespace Shwaeki\DynamicTable\Modules\Export;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shwaeki\DynamicTable\Support\TableRegistry;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Support\TransferProgress;
use Throwable;

class ExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    /** @param array<string, mixed> $state */
    public function __construct(
        public readonly string $tableKey,
        public readonly array $state,
        public readonly string $scope,
        public readonly string $format,
        public readonly string $progressId,
    ) {
        $this->onQueue(config('dynamic-table.excel.queue'));
    }

    public function handle(TableRegistry $registry, ExportManager $manager): void
    {
        try {
            $table = $registry->resolve($this->tableKey);
            $state = TableState::fromArray($this->state, $table);

            TransferProgress::update($this->progressId, [
                'status' => 'running',
                'total' => $manager->estimate($table, $state, $this->scope),
            ]);

            $path = $manager->write($table, $state, $this->scope, $this->format, $this->progressId);
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $key = $manager->store($path, $table, $extension);

            TransferProgress::finish($this->progressId, [
                'file' => $key,
                'filename' => basename($key),
            ]);
        } catch (Throwable $exception) {
            TransferProgress::fail($this->progressId, $exception->getMessage());

            throw $exception;
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Event100k;
use App\Models\Event10m;
use App\Models\Event1m;
use App\Models\ScaleEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScaleCommand extends Command
{
    protected $signature = 'dynamic-table:scale
        {size=100k : 100k, 1m or 10m}
        {--fresh : Empty the table before seeding}';

    protected $description = 'Seed one of the large-dataset demo tables';

    private const SIZES = [
        '100k' => [Event100k::class, 100_000],
        '1m' => [Event1m::class, 1_000_000],
        '10m' => [Event10m::class, 10_000_000],
    ];

    private const CATEGORIES = ['Hardware', 'Software', 'Services', 'Licensing', 'Support', 'Training', 'Shipping', 'Returns'];

    private const REGIONS = ['US', 'GB', 'DE', 'FR', 'JO', 'IL', 'RU', 'AE', 'IN', 'BR'];

    private const STATUSES = ['open', 'pending', 'settled', 'void'];

    public function handle(): int
    {
        $size = strtolower((string) $this->argument('size'));

        if (! isset(self::SIZES[$size])) {
            $this->components->error('Size must be one of: '.implode(', ', array_keys(self::SIZES)));

            return self::FAILURE;
        }

        [$model, $rows] = self::SIZES[$size];

        /** @var ScaleEvent $instance */
        $instance = new $model;
        $table = $instance->getTable();
        $connection = DB::connection($instance->getConnectionName());

        if ($this->option('fresh')) {
            $connection->table($table)->truncate();
        }

        $existing = $connection->table($table)->count();

        if ($existing >= $rows) {
            $this->components->info(number_format($existing)." rows already in {$table}. Use --fresh to rebuild.");

            return self::SUCCESS;
        }

        $remaining = $rows - $existing;

        $this->components->info('Seeding '.number_format($remaining)." rows into {$table}");
        $this->line('  <comment>This is deliberately not part of the normal demo seed — it takes a while and the file gets large.</comment>');
        $this->newLine();

        // Bulk insert with the query log off and one transaction per chunk:
        // hydrating ten million Eloquent models would take hours and gigabytes.
        $connection->disableQueryLog();

        $chunk = 2_000;
        $start = microtime(true);
        $bar = $this->output->createProgressBar($remaining);
        $bar->start();

        $base = now()->subYears(3);

        for ($written = 0; $written < $remaining; $written += $chunk) {
            $batch = [];
            $take = min($chunk, $remaining - $written);

            for ($i = 0; $i < $take; $i++) {
                $n = $existing + $written + $i + 1;

                $batch[] = [
                    'reference' => 'EVT-'.str_pad((string) $n, 10, '0', STR_PAD_LEFT),
                    'category' => self::CATEGORIES[$n % count(self::CATEGORIES)],
                    'region' => self::REGIONS[$n % count(self::REGIONS)],
                    'status' => self::STATUSES[$n % count(self::STATUSES)],
                    'amount' => round(5 + ($n % 4000) * 0.37, 2),
                    'quantity' => ($n % 50) + 1,
                    'is_flagged' => $n % 97 === 0,
                    // Spread across three years so the date filters have range.
                    'occurred_at' => $base->copy()->addMinutes($n % 1_576_800)->toDateTimeString(),
                ];
            }

            $connection->transaction(fn () => $connection->table($table)->insert($batch));

            $bar->advance($take);
        }

        $bar->finish();
        $this->newLine(2);

        $elapsed = round(microtime(true) - $start, 1);
        $total = $connection->table($table)->count();

        $this->components->info(number_format($total)." rows in {$table} ({$elapsed}s)");
        $this->line('  Open <comment>/dynamic-table/examples/scale-'.$size.'</comment>');
        $this->newLine();

        return self::SUCCESS;
    }
}

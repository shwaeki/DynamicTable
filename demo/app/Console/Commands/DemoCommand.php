<?php

namespace App\Console\Commands;

use App\Support\ExampleRegistry;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class DemoCommand extends Command
{
    protected $signature = 'dynamic-table:demo {--fresh : Drop every table before migrating}';

    protected $description = 'Prepare the Laravel DynamicTable demo: migrate and seed';

    public function handle(ExampleRegistry $registry): int
    {
        $this->components->info('Preparing the DynamicTable demo');

        $this->callSilently('migrate'.($this->option('fresh') ? ':fresh' : ''), ['--force' => true]);
        $this->components->task('Migrated');

        $this->callSilently('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $this->components->task('Seeded');

        $this->callSilently('dynamic-table:clear');
        $this->components->task('Cleared metadata cache');

        $this->newLine();
        $this->components->info($registry->all()->count().' interactive examples ready.');
        $this->line('  Run <comment>php artisan serve</comment> and open <comment>/dynamic-table/examples</comment>');
        $this->newLine();

        return self::SUCCESS;
    }
}

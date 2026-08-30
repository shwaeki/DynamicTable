<?php

namespace Shwaeki\DynamicTable\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'dynamic-table:install
        {--config : Publish the configuration file}
        {--lang : Publish the translation files}
        {--views : Publish the theme templates}
        {--migrations : Publish the migrations}
        {--all : Publish everything}';

    protected $description = 'Install Laravel DynamicTable';

    public function handle(): int
    {
        $this->components->info('Installing Laravel DynamicTable');

        $all = (bool) $this->option('all');
        $published = false;

        foreach ([
            'config' => 'dynamic-table-config',
            'lang' => 'dynamic-table-lang',
            'views' => 'dynamic-table-views',
            'migrations' => 'dynamic-table-migrations',
        ] as $option => $tag) {
            if ($all || $this->option($option)) {
                $this->callSilently('vendor:publish', ['--tag' => $tag, '--force' => true]);
                $this->components->task("Published {$option}");
                $published = true;
            }
        }

        if (! $published) {
            $this->components->info('Nothing published — the package works with its bundled defaults.');
            $this->line('  Use <comment>--config</comment>, <comment>--lang</comment>, <comment>--views</comment>, <comment>--migrations</comment> or <comment>--all</comment> to publish files.');
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  1. <comment>php artisan migrate</comment>          (only needed for saved views)');
        $this->line('  2. <comment>php artisan make:dynamic-table UsersTable</comment>');
        $this->line('  3. Add <comment>@dynamicTable(UsersTable::class)</comment> to a Blade view.');
        $this->newLine();

        return self::SUCCESS;
    }
}

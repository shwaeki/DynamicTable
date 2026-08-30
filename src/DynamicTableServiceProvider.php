<?php

namespace Shwaeki\DynamicTable;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Shwaeki\DynamicTable\Commands\ClearCacheCommand;
use Shwaeki\DynamicTable\Commands\InstallCommand;
use Shwaeki\DynamicTable\Commands\MakeTableCommand;
use Shwaeki\DynamicTable\Metadata\MetadataEngine;
use Shwaeki\DynamicTable\Support\AssetManager;
use Shwaeki\DynamicTable\Support\TableRegistry;
use Shwaeki\DynamicTable\Support\TableRenderer;

class DynamicTableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dynamic-table.php', 'dynamic-table');
        $this->mergeConfigFrom(__DIR__.'/../config/dynamic-table-themes.php', 'dynamic-table-themes');

        $this->app->singleton(TableRegistry::class);
        $this->app->singleton(MetadataEngine::class);
        $this->app->singleton(AssetManager::class);
        $this->app->singleton(TableRenderer::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dynamic-table');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'dynamic-table');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerRoutes();
        $this->registerDirectives();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                MakeTableCommand::class,
                InstallCommand::class,
                ClearCacheCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'prefix' => config('dynamic-table.route.prefix', '_dynamic-table'),
            'middleware' => config('dynamic-table.route.middleware', ['web']),
            'as' => 'dynamic-table.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/dynamic-table.php');
        });

        // Assets are served without session/CSRF middleware so they stay cacheable.
        Route::group([
            'prefix' => config('dynamic-table.route.prefix', '_dynamic-table'),
            'as' => 'dynamic-table.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/assets.php');
        });
    }

    protected function registerDirectives(): void
    {
        Blade::directive('dynamicTable', function (string $expression): string {
            return "<?php echo app('".TableRenderer::class."')->render({$expression}); ?>";
        });

        // Optional: explicit asset placement for apps with a strict CSP / bundling setup.
        Blade::directive('dynamicTableStyles', function (): string {
            return "<?php echo app('".AssetManager::class."')->styles(); ?>";
        });

        Blade::directive('dynamicTableScripts', function (): string {
            return "<?php echo app('".AssetManager::class."')->scripts(); ?>";
        });
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/dynamic-table.php' => config_path('dynamic-table.php'),
        ], 'dynamic-table-config');

        $this->publishes([
            __DIR__.'/../config/dynamic-table-themes.php' => config_path('dynamic-table-themes.php'),
        ], 'dynamic-table-themes');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/dynamic-table'),
        ], 'dynamic-table-lang');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/dynamic-table'),
        ], 'dynamic-table-views');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'dynamic-table-migrations');
    }
}

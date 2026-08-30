<?php

namespace App\Providers;

use App\Models\User;
use App\Support\ExampleRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Shwaeki\DynamicTable\Support\Theme;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExampleRegistry::class);
    }

    public function boot(): void
    {
        $this->registerDemoTheme();

        // The demo has no login screen; sign in as a fixed user so the saved
        // views example has an owner, and grant the system-views gate so
        // "Share with everyone" is available.
        if (! app()->runningInConsole() && Auth::guest()) {
            $user = User::query()->first();

            if ($user !== null) {
                Auth::setUser($user);
            }
        }

        Gate::define('manage-dynamic-table-system-views', fn (?User $user): bool => true);

    }

    /**
     * A complete custom theme: one array, no Blade files, no CSS build.
     *
     * The dt-* classes are kept because they carry behaviour — sticky header,
     * resize handles, dialog layout, RTL mirroring — rather than looks.
     */
    private function registerDemoTheme(): void
    {
        Theme::register('demo', [
            'root' => 'dt dt-demo',
            'wrapper' => 'demo-card',
            'toolbar' => 'dt-toolbar demo-toolbar',
            'search' => 'demo-input demo-input-search',
            'button' => 'demo-btn',
            'buttonPrimary' => 'demo-btn demo-btn-primary',
            'buttonDanger' => 'demo-btn demo-btn-danger',
            'input' => 'demo-input',
            'select' => 'demo-input demo-select',
            'scroller' => 'dt-scroller',
            'table' => 'dt-table demo-table',
            'thead' => 'dt-thead demo-thead',
            'th' => 'dt-th demo-th',
            'row' => 'dt-row demo-row',
            'rowSelected' => 'dt-row-selected demo-row-selected',
            'cell' => 'dt-cell demo-cell',
            'footer' => 'dt-footer demo-footer',
            'empty' => 'dt-empty demo-empty',
            'badge' => 'dt-badge demo-badge',
            'menu' => 'dt-menu demo-menu',
            'menuItem' => 'dt-menu-item demo-menu-item',
            'modalBox' => 'dt-modal-box demo-modal',
            'chip' => 'dt-chip demo-chip',
        ]);
    }
}

<?php

/*
|--------------------------------------------------------------------------
| DynamicTable
|--------------------------------------------------------------------------
|
| Published from the package with:
|
|     php artisan vendor:publish --tag=dynamic-table-config
|
| Only the keys this demo actually changes are kept here; everything else
| falls back to the package's own defaults. The whole file is worth reading
| once — it is the one place where a project's tables are configured.
|
*/

return [

    /*
    | Every example that does not say otherwise renders with the package's own
    | theme, so this site shows what a Laravel application gets with no CSS
    | framework and no configuration at all. The package's default is
    | "bootstrap"; the Bootstrap and Tailwind examples name theirs per table.
    */
    'theme' => 'custom',

    /*
    | A theme is one array: slot => CSS classes. Anything defined here is
    | usable immediately as $theme = 'demo' on a table, or as the global
    | 'theme' above. A name of its own starts from "custom", so only the slots
    | listed here change. No service provider, no Blade files, no build.
    |
    | The dynamic-table-* classes stay because they carry behaviour — sticky header,
    | resize handles, dialog layout, RTL mirroring — rather than looks. Colour
    | is not set here: it comes from the CSS tokens, which is what keeps the
    | theme readable in light and dark. The demo overrides those tokens in its
    | own stylesheet under .dynamic-table-demo.
    */
    'themes' => [

        'demo' => [
            'root' => 'dynamic-table dynamic-table-demo',
            'wrapper' => 'demo-card',
            'toolbar' => 'dynamic-table-toolbar demo-toolbar',
            'search' => 'demo-input demo-input-search',
            'button' => 'demo-btn',
            'buttonPrimary' => 'demo-btn demo-btn-primary',
            'buttonDanger' => 'demo-btn demo-btn-danger',
            'input' => 'demo-input',
            'select' => 'demo-input demo-select',
            'scroller' => 'dynamic-table-scroller',
            'table' => 'dynamic-table-table demo-table',
            'thead' => 'dynamic-table-thead demo-thead',
            'th' => 'dynamic-table-th demo-th',
            'row' => 'dynamic-table-row demo-row',
            'rowSelected' => 'dynamic-table-row-selected demo-row-selected',
            'cell' => 'dynamic-table-cell demo-cell',
            'footer' => 'dynamic-table-footer demo-footer',
            'empty' => 'dynamic-table-empty demo-empty',
            'badge' => 'dynamic-table-badge demo-badge',
            'menu' => 'dynamic-table-menu demo-menu',
            'menuItem' => 'dynamic-table-menu-item demo-menu-item',
            'modalBox' => 'dynamic-table-modal-box demo-modal',
            'chip' => 'dynamic-table-chip demo-chip',
        ],

    ],

    /*
    | Where this demo's own source lives, so an example page can link to the
    | file it is showing. The package never reads it — it is the demo's own
    | setting, kept here because this is the demo's config file.
    */
    'source_url' => 'https://github.com/shwaeki/DynamicTable/blob/main',

];

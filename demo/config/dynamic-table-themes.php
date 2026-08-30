<?php

/*
|--------------------------------------------------------------------------
| Themes
|--------------------------------------------------------------------------
|
| Published from the package with:
|
|     php artisan vendor:publish --tag=dynamic-table-themes
|
| A theme is one array: slot => CSS classes. Anything defined here is usable
| immediately as $theme = 'demo' on a table, or as the global 'theme' in
| config/dynamic-table.php. No service provider, no Blade files, no build.
|
| The dt-* classes stay because they carry behaviour — sticky header, resize
| handles, dialog layout, RTL mirroring — rather than looks. Colour is not
| set here: it comes from the CSS tokens, which is what keeps the theme
| readable in light and dark. The demo overrides those tokens in its own
| stylesheet under .dt-demo.
|
*/

return [

    'demo' => [
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
    ],

];

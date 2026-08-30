<?php

/*
|--------------------------------------------------------------------------
| Themes
|--------------------------------------------------------------------------
|
| A theme is one array: slot name => CSS classes. There is a single Blade
| template and a single JavaScript renderer, both reading this map, so a
| complete visual theme is this file rather than a folder of partials.
|
| Publish it with:
|
|     php artisan vendor:publish --tag=dynamic-table-themes
|
| and edit it here. Nothing needs to be registered in a service provider:
| any key you add below is usable straight away as
|
|     protected ?string $theme = 'brand';
|
| or as the global 'theme' in config/dynamic-table.php.
|
| Two rules, and only two:
|
| 1. Keep the structural dt-* classes. They carry behaviour — sticky header,
|    resize handles, dialog layout, RTL mirroring — not looks. Add your own
|    classes alongside them.
| 2. Do not put colour here. Surfaces, text and borders come from the CSS
|    tokens (--dt-ink, --dt-surface, --dt-border, --dt-accent …), which is
|    what keeps every theme legible in light and dark and obedient to
|    data-dt-scheme. Override the tokens in your own stylesheet instead:
|
|        .dt-brand { --dt-accent: #7c3aed; --dt-radius: 14px; }
|
| The four built-in themes ('tailwind', 'bootstrap', 'minimal', 'bordered')
| are defined in the package and need nothing here. Naming a theme below with
| one of those names replaces it.
|
*/

return [

    /*
    | An example to copy. Every slot is optional: anything you leave out keeps
    | the package's structural default, so a theme can be three lines.
    */

    // 'brand' => [
    //     'root' => 'dt dt-brand',
    //     'wrapper' => 'rounded-2xl border shadow-sm overflow-hidden',
    //     'toolbar' => 'dt-toolbar flex items-center gap-2 p-3 border-b',
    //     'search' => 'input input-sm w-64',
    //     'button' => 'btn btn-sm',
    //     'buttonPrimary' => 'btn btn-sm btn-primary',
    //     'buttonDanger' => 'btn btn-sm btn-error',
    //     'input' => 'input input-sm w-full',
    //     'select' => 'select select-sm',
    //     'scroller' => 'dt-scroller overflow-x-auto',
    //     'table' => 'dt-table table w-full',
    //     'thead' => 'dt-thead',
    //     'th' => 'dt-th text-xs uppercase tracking-wide',
    //     'row' => 'dt-row',
    //     'rowSelected' => 'dt-row-selected',
    //     'cell' => 'dt-cell px-3 py-2',
    //     'footer' => 'dt-footer flex items-center justify-between p-3 border-t',
    //     'empty' => 'dt-empty py-16 text-center',
    //     'badge' => 'dt-badge badge',
    //     'menu' => 'dt-menu rounded-lg border p-1 shadow-lg',
    //     'menuItem' => 'dt-menu-item w-full rounded px-2 py-1.5 text-start',
    //     'modalBox' => 'dt-modal-box w-full max-w-2xl rounded-xl p-4 shadow-2xl',
    //     'chip' => 'dt-chip badge',
    // ],

];

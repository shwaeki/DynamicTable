<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    | "tailwind" and "bootstrap" expect that framework on the page; "minimal"
    | and "bordered" need no framework at all. Or the name of your own theme,
    | registered with Theme::register() or defined under "themes" below.
    */
    'theme' => 'tailwind',

    /*
    |--------------------------------------------------------------------------
    | Your own themes
    |--------------------------------------------------------------------------
    | A theme is one array: slot name => CSS classes. There is a single Blade
    | template and a single JavaScript renderer, both reading this map, so a
    | complete visual theme is an array here rather than a folder of partials.
    |
    | Anything you add is usable straight away — nothing to register in a
    | service provider — as the "theme" above, or per table:
    |
    |     protected ?string $theme = 'brand';
    |
    | The four built-in themes ("tailwind", "bootstrap", "minimal", "bordered")
    | are defined in the package and need nothing here; naming a theme below
    | with one of those names replaces it.
    |
    | Two rules, and only two:
    |
    | 1. Keep the structural dynamic-table-* classes. They carry behaviour —
    |    sticky header, resize handles, dialog layout, RTL mirroring — not
    |    looks. Add your own classes alongside them.
    | 2. Do not put colour here. Surfaces, text and borders come from the CSS
    |    tokens (--dynamic-table-ink, --dynamic-table-surface,
    |    --dynamic-table-border, --dynamic-table-accent …), which is what keeps
    |    every theme legible in light and dark and obedient to
    |    data-dynamic-table-scheme. Override the tokens in your own stylesheet:
    |
    |        .dynamic-table-brand {
    |            --dynamic-table-accent: #7c3aed;
    |            --dynamic-table-radius: 14px;
    |        }
    |
    | "extends" starts from a theme that already works and changes only what
    | you name, which is usually all an admin template needs:
    |
    |     'metronic' => [
    |         'extends' => 'bootstrap',
    |         'badge' => 'badge badge-light-{tone}',
    |     ],
    |
    | {tone} is where a badge's tone goes — success, danger, warning, info,
    | primary, neutral. Leave it out and the tone is appended instead, as
    | dynamic-table-badge-success, which the package stylesheet paints.
    */
    'themes' => [

        // 'brand' => [
        //     'root' => 'dynamic-table dynamic-table-brand',
        //     'wrapper' => 'rounded-2xl border shadow-sm overflow-hidden',
        //     'toolbar' => 'dynamic-table-toolbar flex items-center gap-2 p-3 border-b',
        //     'search' => 'input input-sm w-64',
        //     'button' => 'btn btn-sm',
        //     'buttonPrimary' => 'btn btn-sm btn-primary',
        //     'buttonDanger' => 'btn btn-sm btn-error',
        //     'input' => 'input input-sm w-full',
        //     'select' => 'select select-sm',
        //     'scroller' => 'dynamic-table-scroller overflow-x-auto',
        //     'table' => 'dynamic-table-table table w-full',
        //     'thead' => 'dynamic-table-thead',
        //     'th' => 'dynamic-table-th text-xs uppercase tracking-wide',
        //     'row' => 'dynamic-table-row',
        //     'rowSelected' => 'dynamic-table-row-selected',
        //     'cell' => 'dynamic-table-cell px-3 py-2',
        //     'footer' => 'dynamic-table-footer flex items-center justify-between p-3 border-t',
        //     'empty' => 'dynamic-table-empty py-16 text-center',
        //     'badge' => 'dynamic-table-badge badge',
        //     'menu' => 'dynamic-table-menu rounded-lg border p-1 shadow-lg',
        //     'menuItem' => 'dynamic-table-menu-item w-full rounded px-2 py-1.5 text-start',
        //     'modalBox' => 'dynamic-table-modal-box w-full max-w-2xl rounded-xl p-4 shadow-2xl',
        //     'chip' => 'dynamic-table-chip badge',
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Table height
    |--------------------------------------------------------------------------
    | How tall a table's scroll area may grow, as any CSS length. This is what
    | makes the sticky header stick: a header can only stay put inside a box
    | that scrolls. Set it to null (or "none") to let the page own the
    | scrolling instead, in which case the header scrolls away with it.
    | Override per table with $maxHeight.
    */
    'table' => [
        'max_height' => '70vh',
    ],

    /*
    |--------------------------------------------------------------------------
    | Date and time patterns
    |--------------------------------------------------------------------------
    | How a date, a time and a datetime column read when the column does not
    | say otherwise. null keeps each locale's own pattern from its language
    | file — "9 Mar 2026" in English, "٩ مارس ٢٠٢٦" in Arabic.
    |
    | Write them either way: dd/mm/yyyy and hh:ii as a spreadsheet would, or
    | PHP's own d/m/Y and H:i. Month names still follow the locale.
    |
    | One column overrides all of this with
    |
    |     'created_at' => ['format' => 'dd/mm/yyyy'],
    */
    'formats' => [
        'date' => null,
        'time' => null,
        'datetime' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Direction
    |--------------------------------------------------------------------------
    | null = detect from the application locale (ar/he/fa/ur => rtl).
    */
    'direction' => null,

    /*
    |--------------------------------------------------------------------------
    | Colour scheme
    |--------------------------------------------------------------------------
    | null = follow the viewer's operating system (prefers-color-scheme).
    | "light" or "dark" forces one, for every theme alike.
    */
    'scheme' => null,

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    | How the filter builder, column picker, import and action panels are
    | presented.
    |
    |   modal      a centred dialog over the page (default)
    |   offcanvas  a drawer that slides in from the side
    |
    | "side" accepts "end" and "start", which follow the table's reading
    | direction, or an explicit "left" / "right". A single table can override
    | all of this with $panels.
    */
    'panels' => [
        'mode' => 'modal',
        'side' => 'end',
        'width' => '30rem',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    | Where DynamicTable classes live. Discovered classes are registered by
    | their stable table key. Only registered tables can be resolved from an
    | HTTP request, which keeps the data endpoint a strict allowlist.
    */
    'tables' => [
        'paths' => [
            app_path('DynamicTables'),
        ],
        'register' => [
            // 'users' => App\DynamicTables\UsersTable::class,
        ],
    ],

    'pagination' => [
        'default' => 25,
        'options' => [10, 25, 50, 100],
        'max' => 500,

        /*
        | Past this many rows, "auto" pagination stops counting the result set
        | and shows previous/next instead: at that size the COUNT(*) costs more
        | than the page. 0 always counts.
        */
        'count_threshold' => 250000,

        /*
        | Scroll the top of the table back into view when the page changes —
        | but only when it has actually scrolled out of sight. Style the resting
        | position with CSS: .dynamic-table { scroll-margin-top: 5rem }
        */
        'scroll_on_page' => true,
    ],

    'responsive' => [
        /*
        | The master switch. false turns responsive handling off for every
        | table; a single table can still opt out with $responsive = 'none' or
        | the "-responsive" feature flag.
        */
        'enabled' => true,

        /*
        | What a table does on a narrow screen unless it says otherwise:
        |
        |   collapse  hide the columns that do not fit and reveal them in an
        |             expandable child row (DataTables Responsive behaviour)
        |   scroll    keep every column and scroll horizontally
        |   cards     stack each row into a labelled card below the breakpoint
        |   none      no handling at all
        */
        'mode' => 'collapse',

        /*
        | Width in pixels below which "cards" mode stacks rows. The "collapse"
        | mode does not use a breakpoint: it measures the table and hides only
        | the columns that genuinely do not fit.
        */
        'breakpoint' => 640,
    ],

    'search' => [
        'debounce' => 350,
        'min_length' => 1,
        'max_auto_columns' => 6,
    ],

    'route' => [
        'prefix' => '_dynamic-table',
        'middleware' => ['web'],
    ],

    'cache' => [
        /*
        | One of only two values here that reads the environment, because it is
        | genuinely per-environment: you turn metadata caching off while working
        | on models and leave it on in production. Everything else belongs in
        | this file, where it can be reviewed in a diff.
        */
        'metadata' => env('DYNAMIC_TABLE_CACHE', true),
        'store' => null,
        'ttl' => 86400,
        'prefix' => 'dynamic-table',
    ],

    'views' => [
        'enabled' => true,
        'table' => 'dynamic_table_views',
        'shares_table' => 'dynamic_table_view_shares',
        'system_ability' => 'manage-dynamic-table-system-views',
        'max_per_user' => 100,

        /*
        | Sharing a view with named people.
        |
        | Sharing grants read access only — the owner keeps the right to rename,
        | edit and delete. A recipient who wants their own version saves a copy.
        |
        | "model" defaults to the model behind your auth provider.
        */
        'sharing' => [
            'enabled' => true,
            'model' => null,
            'name_column' => 'name',
            'search_columns' => ['name', 'email'],
            'max_results' => 20,
        ],
    ],

    'excel' => [
        /*
        | "auto" offers XLSX whenever the application already has a spreadsheet
        | library — openspout first, then PhpSpreadsheet, neither of which is a
        | dependency of this package. "csv" declines XLSX outright, even when
        | one of them is installed as somebody else's dependency, and the
        | export dialog then offers CSV alone.
        */
        /*
        | Which library writes and reads XLSX. "auto" prefers openspout, whose
        | memory is flat whatever the row count, and falls back to
        | PhpSpreadsheet. Name one to pin it. "csv" declines XLSX outright, even
        | when a library is installed as somebody else's dependency, and the
        | dialogs then offer CSV alone.
        */
        'adapter' => 'auto', // auto | openspout | phpspreadsheet | csv

        /*
        | The format the export and import dialogs open on, and the one the
        | import template is written in. Falls back to CSV wherever XLSX is not
        | available.
        */
        'default_format' => 'xlsx', // xlsx | csv

        /*
        | How an exported XLSX looks.
        |
        |   'TableStyleMedium2'  a real Excel table — the one "Format as Table"
        |                        makes, with the Table Design ribbon, structured
        |                        references and a name. Excel's own built-ins:
        |                        TableStyleLight1-21, TableStyleMedium1-28,
        |                        TableStyleDark1-11. An unknown name throws
        |                        rather than being quietly ignored.
        |   true                 a styled range: dark headings, banded rows.
        |   false                a bare grid of values — worth it when the file
        |                        is read by a program rather than by a person.
        |
        | The heading stays frozen and the columns stay sized to their content
        | in every mode; nobody has ever wanted those gone.
        */
        'style' => 'TableStyleMedium2',
        'queue_threshold' => 5000,
        'chunk' => 1000,
        'disk' => null,
        'directory' => 'dynamic-table',
        'queue' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Print
    |--------------------------------------------------------------------------
    | The Blade view the print button opens, and the most rows one printout may
    | contain. Publish the views to edit the template:
    |
    |     php artisan vendor:publish --tag=dynamic-table-views
    |
    | then edit resources/views/vendor/dynamic-table/print.blade.php, or point
    | "view" at a template of your own.
    */
    'print' => [
        'view' => 'dynamic-table::print',
        'max_rows' => 2000,
        'paper' => 'A4',

        /*
        | Open the print dialog as soon as the page is ready, and close the tab
        | once it is dismissed. Add ?auto=0 to any print URL to look at the page
        | instead — which is how you work on the template.
        */
        'auto' => true,

        /*
        | Stylesheets the print page loads before its own, keyed by theme. The
        | print template stands on its own, so this exists only to keep a
        | Bootstrap or Tailwind class map looking like itself on paper. Set it
        | to [] to load nothing, or point it at your compiled CSS.
        */
        'stylesheets' => null,
    ],

    'security' => [
        /*
        | Attribute names that are never exposed by automatic discovery,
        | matched case-insensitively as substrings.
        */
        'blocked_columns' => [
            'password',
            'remember_token',
            'secret',
            'token',
            'api_key',
            'private_key',
            'two_factor',
            'otp',
        ],
        'max_filters' => 40,
        'max_filter_depth' => 4,
        'max_relation_depth' => 3,
    ],

    'performance' => [
        /*
        | Show a developer panel with query count / time / memory. Per-machine
        | rather than per-project, so this one reads the environment too. It is
        | suppressed in production regardless of the value.
        */
        'panel' => env('DYNAMIC_TABLE_PANEL', false),
    ],

    'assets' => [
        /*
        | The package serves its JS/CSS through a route so no publishing step
        | is required. Set to false and use @dynamicTableStyles/@dynamicTableScripts
        | with your own bundler if you prefer.
        */
        'inject' => true,
        'version' => null,
    ],

];

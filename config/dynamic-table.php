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
        | position with CSS: .dt { scroll-margin-top: 5rem }
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
        'adapter' => 'auto', // auto | laravel-excel | openspout | csv
        'queue_threshold' => 5000,
        'chunk' => 1000,
        'disk' => null,
        'directory' => 'dynamic-table',
        'queue' => null,
    ],

    'spreadsheet' => [
        'engine' => 'tabulator',
        'cdn' => null,
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

    'source_url' => null,
];

<?php

return [

    // "custom" (needs no CSS framework), "bootstrap" or "tailwind". Any other
    // name resolves to "custom". Per table: $theme.
    'theme' => 'bootstrap',

    /*
     * Your own class maps: slot => CSS classes. See docs/themes.md.
     *
     * A name matching a built-in overrides that theme's slots; a name of your
     * own starts from "custom", so list only what you change. Keep the
     * structural dynamic-table-* classes — they carry behaviour, not looks —
     * and leave colour to the CSS tokens, which is what keeps a theme legible
     * in light and dark. {tone} is where a badge's tone goes.
     */
    'themes' => [

        // 'brand' => [
        //     'root' => 'dynamic-table dynamic-table-brand',
        //     'button' => 'btn btn-sm',
        //     'buttonPrimary' => 'btn btn-sm btn-primary',
        //     'buttonDanger' => 'btn btn-sm btn-danger',
        //     'badge' => 'badge badge-{tone}',
        // ],

    ],

    // How tall a table's scroll area may grow. This is what makes the sticky
    // header stick; null or "none" lets the page own the scrolling instead.
    'table' => [
        'max_height' => '70vh',
    ],

    // null keeps each locale's own patterns. Write them as a spreadsheet would
    // (dd/mm/yyyy, hh:ii) or as PHP does (d/m/Y, H:i); one column overrides
    // with ['format' => 'dd/mm/yyyy'].
    'formats' => [
        'date' => null,
        'time' => null,
        'datetime' => null,
    ],

    // null = detect from the application locale (ar/he/fa/ur => rtl).
    'direction' => null,

    // Light unless you say otherwise: "dark" forces dark for every theme
    // alike, null follows the viewer's OS. Per table: $scheme.
    'scheme' => 'light',

    // The filter builder, column picker, import and action panels.
    // Per table: $panels.
    'panels' => [
        'mode' => 'modal',      // modal | offcanvas
        'side' => 'end',        // end | start (follow reading direction) | left | right
        'width' => '30rem',
    ],

    // Where DynamicTable classes live, registered by their stable table key.
    // Only registered tables resolve from a request, which is what keeps the
    // data endpoint a strict allowlist.
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

        // Past this many rows "auto" stops counting the result set and shows
        // previous/next: the COUNT(*) costs more than the page. 0 always counts.
        'count_threshold' => 250000,

        // Scroll the table back into view when the page changes, but only when
        // it has scrolled out of sight. Resting position is CSS:
        // .dynamic-table { scroll-margin-top: 5rem }
        'scroll_on_page' => true,
    ],

    'responsive' => [
        // The master switch. A table can still opt out on its own with
        // $responsive = 'none' or the "-responsive" feature flag.
        'enabled' => true,

        // collapse  hide the columns that do not fit, reveal them in a child row
        // scroll    keep every column and scroll horizontally
        // cards     stack each row into a labelled card below the breakpoint
        // none      no handling at all
        'mode' => 'collapse',

        // Width in pixels below which "cards" stacks rows. "collapse" needs no
        // breakpoint: it measures the table and hides only what does not fit.
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
        // Genuinely per-environment: off while you work on models, on in
        // production. Clear it after migrating with `dynamic-table:clear`.
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

        // Sharing grants read access only — the owner keeps rename, edit and
        // delete, and a recipient who wants their own version saves a copy.
        // "model" defaults to the model behind your auth provider.
        'sharing' => [
            'enabled' => true,
            'model' => null,
            'name_column' => 'name',
            'search_columns' => ['name', 'email'],
            'max_results' => 20,
        ],
    ],

    'excel' => [
        // Which library writes and reads XLSX. "auto" prefers openspout, whose
        // memory is flat whatever the row count, and falls back to
        // PhpSpreadsheet. "csv" declines XLSX even when one is installed as
        // somebody else's dependency, and the dialogs then offer CSV alone.
        'adapter' => 'auto',            // auto | openspout | phpspreadsheet | csv

        // What the export and import dialogs open on, and the format of the
        // import template. Falls back to CSV wherever XLSX is unavailable.
        'default_format' => 'xlsx',     // xlsx | csv

        // A style name makes a real Excel table, the one "Format as Table"
        // gives you: TableStyleLight1-21, TableStyleMedium1-28,
        // TableStyleDark1-11 (an unknown name throws rather than being
        // ignored). true is a styled range, false a bare grid of values. The
        // heading stays frozen and the columns sized in every mode.
        'style' => 'TableStyleMedium2',

        'queue_threshold' => 5000,
        'chunk' => 1000,
        'disk' => null,
        'directory' => 'dynamic-table',
        'queue' => null,
    ],

    'print' => [
        // Publish the views to edit the template, or point "view" at your own:
        // php artisan vendor:publish --tag=dynamic-table-views
        'view' => 'dynamic-table::print',
        'max_rows' => 2000,
        'paper' => 'A4',

        // Open the print dialog as soon as the page is ready and close the tab
        // once it is dismissed. Add ?auto=0 to any print URL to look at the
        // page instead — which is how you work on the template.
        'auto' => true,

        // Stylesheets the print page loads before its own, keyed by theme. The
        // template stands on its own, so this exists only to keep a Bootstrap
        // or Tailwind class map looking like itself on paper. [] loads nothing.
        'stylesheets' => null,
    ],

    'security' => [
        // Never exposed by automatic discovery, matched case-insensitively as
        // substrings.
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

        // How many aggregate fields one plural relation may contribute to the
        // filter builder and the column picker — count, exists, and the sums,
        // averages and extremes of the columns where those mean something. The
        // cap is for the model with thirty numeric columns, where the honest
        // list would be longer than anybody wants to scroll.
        'max_aggregate_fields' => 40,
    ],

    // A developer panel with query count, time and memory. Per-machine rather
    // than per-project, so it reads the environment; suppressed in production
    // whatever the value.
    'performance' => [
        'panel' => env('DYNAMIC_TABLE_PANEL', false),
    ],

    // The package serves its JS and CSS through a route, so there is no
    // publishing step. false = place @dynamicTableStyles and
    // @dynamicTableScripts yourself, with your own bundler.
    'assets' => [
        'inject' => true,
        'version' => null,
    ],

];

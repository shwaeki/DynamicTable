# Configuration

Every value has a working default, so you only need to publish the file if you
want to change something.

```bash
php artisan dynamic-table:install --config
```

```php
return [
    // 'custom' (needs no CSS framework) | 'bootstrap' | 'tailwind'
    'theme' => 'bootstrap',

    // null = detect from the application locale (ar/he/fa/ur => rtl)
    'direction' => null,

    // 'light' | 'dark'; null follows the viewer's OS
    'scheme' => 'light',

    // how tall a table's scroll area may grow — what makes the header stick;
    // null or 'none' lets the page own the scrolling instead
    'table' => [
        'max_height' => '70vh',
    ],

    // application-wide date patterns; null keeps each locale's own
    'formats' => [
        'date' => null,
        'time' => null,
        'datetime' => null,
    ],

    'panels' => [
        'mode' => 'modal',      // or 'offcanvas'
        'side' => 'end',        // 'end' | 'start' | 'left' | 'right'
        'width' => '30rem',
    ],

    'tables' => [
        'paths' => [app_path('DynamicTables')],
        'register' => [],
    ],

    'pagination' => [
        'default' => 25,
        'options' => [10, 25, 50, 100],
        'max' => 500,

        // past this many rows, 'auto' pagination stops counting; 0 always counts
        'count_threshold' => 250000,

        // scroll the table back into view when the page changes
        'scroll_on_page' => true,
    ],

    'responsive' => [
        'enabled' => true,
        'mode' => 'collapse',   // 'collapse' | 'scroll' | 'cards' | 'none'
        'breakpoint' => 640,
    ],

    'search' => [
        'debounce' => 350,          // ms
        'min_length' => 1,
        'max_auto_columns' => 6,    // ceiling for automatic searchable columns
    ],

    'route' => [
        'prefix' => '_dynamic-table',
        'middleware' => ['web'],
    ],

    'cache' => [
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

        'sharing' => [
            'enabled' => true,
            'model' => null,          // defaults to your auth provider's model
            'name_column' => 'name',
            'search_columns' => ['name', 'email'],
            'max_results' => 20,
        ],
    ],

    'excel' => [
        'adapter' => 'auto',        // 'csv' declines XLSX even where it is possible
        'queue_threshold' => 5000,  // 0 disables queueing
        'chunk' => 1000,
        'disk' => null,
        'directory' => 'dynamic-table',
        'queue' => null,
    ],

    'security' => [
        'blocked_columns' => [
            'password', 'remember_token', 'secret', 'token',
            'api_key', 'private_key', 'two_factor', 'otp',
        ],
        'max_filters' => 40,
        'max_filter_depth' => 4,
        'max_relation_depth' => 3,
    ],

    'performance' => [
        'panel' => env('DYNAMIC_TABLE_PANEL', false),
    ],

    'assets' => [
        'inject' => true,
        'version' => null,
    ],
];
```

## Notes on the ones that matter

**`route.middleware`** — the endpoints run in the `web` group so CSRF and
session auth apply. Add `'auth'` if every table in your app is behind login.

**`cache.metadata`** — schema introspection is cached for `ttl` seconds. Only
schema *shape* is cached; nothing user-specific ever is. Clear after migrating:

```bash
php artisan dynamic-table:clear
```

**`security.blocked_columns`** — substring matches, case-insensitive. This is a
floor, not a fence: use `$hiddenColumns` or `$allowedColumns` for
application-specific rules.

**`excel.queue_threshold`** — exports and imports larger than this move to a
queue automatically, with poll-based progress. Set to `0` to always run inline.

**`pagination.count_threshold`** — past this size the table stops running
`COUNT(*)` on every request and shows previous/next instead. See
[Performance](performance.md#counting-is-the-thing-that-stops-scaling).

**`responsive`** — see [Responsive](responsive.md) for the three modes and how
columns are chosen for collapsing.

**`performance.panel`** — the developer panel is suppressed in production
regardless of this value.

**`assets.inject`** — set to `false` and place `@dynamicTableStyles` /
`@dynamicTableScripts` yourself when you have a strict CSP or your own bundler.

**`table.max_height`** — the sticky header needs a box that scrolls, so this is
what makes it stick. `null` or `'none'` gives the table page-flow height, and
the header then scrolls away with the page.

**`excel.adapter`** — `'auto'` offers XLSX whenever openspout or PhpSpreadsheet
is installed; `'csv'` declines it even then, and the export dialog offers CSV
alone.

**`themes`** — your own class maps. A name matching a built-in overrides that
theme's slots; a name of your own starts from `custom`, so list only what you
change. See [Themes](themes.md).

```php
'themes' => [
    'bootstrap' => ['badge' => 'badge badge-light-{tone}'],
    'brand' => ['table' => 'dynamic-table-table my-table', /* … */],
],
```

## Panels: modal or offcanvas

The filter builder, column picker, import and action panels can be shown as a
centred dialog or as a drawer that slides in from the side.

```php
'panels' => [
    'mode' => 'modal',      // or 'offcanvas'
    'side' => 'end',        // 'end' / 'start' follow the reading direction;
                            // 'left' / 'right' are explicit
    'width' => '30rem',     // drawer width; ignored by modals
],
```

Per table:

```php
protected ?string $panels = 'offcanvas';
```

`end` and `start` are resolved against the table's direction, so an offcanvas
opens from the right in English and from the left in Arabic without any extra
configuration. Below 640px a drawer becomes a full-width sheet.

Both presentations share the same markup, focus trap and Escape handling — a
panel never knows which one it is being shown in, so this is safe to change at
any time.

## Environment variables

Only two settings read the environment, because only two are genuinely
per-environment rather than per-project:

```
DYNAMIC_TABLE_CACHE=false     # turn metadata caching off while editing models
DYNAMIC_TABLE_PANEL=true      # show the developer performance panel
```

Everything else lives in the config file, where it can be reviewed in a diff and
published with:

```bash
php artisan dynamic-table:install --config
```

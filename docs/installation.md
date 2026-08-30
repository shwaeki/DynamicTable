# Installation

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| Laravel | 11, 12 or 13 |
| Database | MySQL, MariaDB, PostgreSQL or SQLite |
| JavaScript build step | none |
| CSS framework | none required |

## Install

```bash
composer require shwaeki/laravel-dynamictable
```

That is enough to render a table. The service provider is auto-discovered, the
config has working defaults, and the CSS/JS are served from the package through
a versioned route — there is nothing to publish and nothing to build.

## Saved views (optional)

Saved views are the only feature that needs a database table:

```bash
php artisan migrate
```

The migration ships with the package and is loaded automatically. If you would
rather own it, publish it first:

```bash
php artisan vendor:publish --tag=dynamic-table-migrations
```

## Publishing (all optional)

```bash
php artisan dynamic-table:install --config      # config/dynamic-table.php
php artisan dynamic-table:install --lang        # lang/vendor/dynamic-table
php artisan dynamic-table:install --views       # the Blade template + theme partials
php artisan dynamic-table:install --migrations
php artisan dynamic-table:install --all
```

Publish only what you intend to change. Un-published files keep receiving
upstream fixes.

## Choosing a theme

```php
// config/dynamic-table.php
'theme' => 'tailwind',   // or 'bootstrap', or your own
```

Bootstrap users are never served Tailwind classes and vice versa — a theme is
just a class map. See [Themes](themes.md).

## Assets and Content Security Policy

By default the `@dynamicTable` directive injects one stylesheet link and one
`<script type="module">` tag, once per response. If your CSP or bundler needs
them elsewhere:

```php
// config/dynamic-table.php
'assets' => ['inject' => false],
```

```blade
<head>
    @dynamicTableStyles
</head>
<body>
    @dynamicTable(UsersTable::class)
    @dynamicTableScripts
</body>
```

## Where table classes live

`app/DynamicTables` is scanned by default. Add more locations, or register
tables explicitly, in the config:

```php
'tables' => [
    'paths' => [app_path('DynamicTables')],
    'register' => [
        'users' => App\DynamicTables\UsersTable::class,
    ],
],
```

Discovery results are cached. After adding a table in production:

```bash
php artisan dynamic-table:clear
```

## Optional extras

```bash
composer require openspout/openspout      # XLSX export/import, constant memory
```

XLSX also works if `phpoffice/phpspreadsheet` (or Laravel Excel) is already
installed. CSV works with no extra package at all.

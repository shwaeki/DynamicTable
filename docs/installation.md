# Installation

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| Laravel | 10, 11, 12 or 13 |
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

Nothing has to be published: the package works from its bundled defaults, and
un-published files keep receiving upstream fixes. Publish only what you intend
to change.

```bash
php artisan dynamic-table:install --config      # config/dynamic-table.php, themes included
php artisan dynamic-table:install --lang        # lang/vendor/dynamic-table
php artisan dynamic-table:install --views       # the Blade template
php artisan dynamic-table:install --migrations  # the saved-views tables
php artisan dynamic-table:install --all
```

Every one of those is a `vendor:publish` tag, if you prefer the standard
command or need it in a deploy script:

```bash
php artisan vendor:publish --tag=dynamic-table-config
php artisan vendor:publish --tag=dynamic-table-lang
php artisan vendor:publish --tag=dynamic-table-views
php artisan vendor:publish --tag=dynamic-table-migrations

# or, interactively, and pick from the list
php artisan vendor:publish --provider="Shwaeki\DynamicTable\DynamicTableServiceProvider"
```

Add `--force` to overwrite files you have already published — which will
discard your edits to them, so keep it out of habit and out of deploys.

| Tag | Publishes | Publish it when |
|---|---|---|
| `dynamic-table-config` | `config/dynamic-table.php` | Changing defaults: theme, page size, table height, panel mode, responsive strategy, caching — and defining your own themes under `themes`. |
| `dynamic-table-lang` | `lang/vendor/dynamic-table/{locale}` | Rewording the UI, or adding a language the package does not ship. |
| `dynamic-table-views` | `resources/views/vendor/dynamic-table` | Changing the markup itself. Rarely needed — a theme is a class map, so looks do not require this. |
| `dynamic-table-migrations` | The saved-views tables | You want to own them, e.g. to change the table names or add columns. |

Assets are **not** published: CSS and JavaScript are served from the package
through a versioned route, which is why there is no build step and why a
`composer update` ships the fixes with it.

## Choosing a theme

```php
// config/dynamic-table.php
'theme' => 'tailwind',   // or 'bootstrap', or your own
```

Bootstrap users are never served Tailwind classes and vice versa — a theme is
just a class map. See [Themes](themes.md).

## Rendering a table

Two ways, the same renderer behind both:

```blade
@dynamicTable(UsersTable::class)
@dynamicTable(UsersTable::class, ['theme' => 'bootstrap', 'params' => ['status' => 'open']])

<x-dynamic-table :table="UsersTable::class" />
<x-dynamic-table :table="$table" theme="bootstrap" :params="['status' => 'open']" />
```

The component exists because a directive is awkward where tables increasingly
live: inside a Livewire component's view, inside another component's slot, or
anywhere the arguments are variables rather than literals. Anything the
directive takes in its options array is an attribute on the component.

## Livewire, Inertia and Turbo

Tables survive a page that is replaced without being loaded. The runtime
re-mounts on `livewire:navigated`, `livewire:load`, `turbo:load`,
`inertia:success` and `inertia:navigate`, and on Livewire's `morphed` hook — so
a table that arrives with a new page is picked up, and one that leaves is let
go of.

**Letting go matters as much as picking up.** A table puts listeners on the
document and the window — a resize handler, a `popstate` handler, the ones
behind [external parameter controls](tables.md#parameters) — and those do not
go away with the element. Every one of them is registered against the table's
own abort signal, and a table whose element has left the page is destroyed:
its observer disconnected, its in-flight request aborted, its listeners
removed. Without that, an application that visits ten pages ends up with ten
tables' worth of handlers still firing at a DOM that no longer exists.

A table inside a Livewire component is usually best marked `wire:ignore`:

```blade
<div wire:ignore>
    <x-dynamic-table :table="UsersTable::class" />
</div>
```

The table owns its own state and talks to its own endpoint, so there is nothing
for Livewire to morph — and morphing it would throw away the DOM the runtime is
holding on every re-render.

The runtime is also reachable directly, which is what an SPA integration
occasionally needs:

```js
window.DynamicTable.boot();       // mount any table not yet mounted, sweep dead ones
window.DynamicTable.find('users').refresh();
window.DynamicTable.sweep();      // destroy tables whose element has gone
```

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

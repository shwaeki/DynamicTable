# Laravel DynamicTable — documentation

> Maximum functionality with minimum configuration.

```php
class UsersTable extends DynamicTable
{
    protected string $model = User::class;
}
```

```blade
@dynamicTable(UsersTable::class)
```

## Getting started

- [Installation](installation.md)
- [Quick start](quick-start.md)
- [The table class](tables.md)
- [Configuration](configuration.md)
- [All features](features.md) — every feature flag: what it does, costs and implies

## Building tables

- [Columns](columns.md) — discovery, types, formatting, relationships
- [Search and filters](filters.md) — global search, column search, the filter builder
- [Saved views](views.md) — user views, system views, defaults, URL state
- [Editing and actions](editing.md) — inline editing, spreadsheet mode, bulk actions
- [Export and import](export-import.md)

## Presentation

- [Themes](themes.md) — Bootstrap, Tailwind, minimal, bordered, custom
- [Responsive](responsive.md) — collapsing columns, cards, scroll
- [Localization and RTL](localization.md) — ar, he, en, ru

## Operating it

- [Performance](performance.md) — budgets, N+1, caching, large datasets
- [Security](security.md) — the full threat checklist
- [Testing](testing.md)
- [Troubleshooting & FAQ](troubleshooting.md)

## Going deeper

- [Architecture & decisions](architecture.md) — why Livewire is not a
  dependency, how the spreadsheet and Excel libraries were chosen, the query
  strategy
- [Extending](extending.md) — events, DOM API, adapters, public vs internal API

## The demo

A complete interactive example application lives in [`demo/`](../demo). Every
example renders a real table and shows the real source file that produced it.

```bash
cd demo && composer install && php artisan dynamic-table:demo && php artisan serve
```

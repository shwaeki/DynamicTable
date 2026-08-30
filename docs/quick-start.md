# Quick start

Thirty seconds, start to finish.

## 1. Create a table

```bash
php artisan make:dynamic-table UsersTable
```

```php
namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

class UsersTable extends DynamicTable
{
    protected string $model = User::class;
}
```

## 2. Render it

```blade
@dynamicTable(UsersTable::class)
```

That's the whole API.

## What you get without writing anything else

- Columns discovered from the model's schema, casts and relationships
- `department_id` shown as the department's **name**, eager loaded, no N+1
- Server-side search, sorting and pagination
- The advanced filter builder
- Dates, numbers, booleans and enums formatted for the current locale
- RTL when the locale is Arabic or Hebrew
- `password`, `remember_token` and anything else sensitive never exposed

## Turning on more

Features that cost something — a query, a JS module, extra state — are opt-in:

```php
class UsersTable extends DynamicTable
{
    protected string $model = User::class;

    protected array $features = [
        'views',
        'export',
        'bulk-actions',
        'inline_edit',
    ];
}
```

## Choosing columns explicitly

Optional. Only do this when discovery isn't what you want:

```php
protected function columns(): array
{
    return [
        'name',
        'email',
        'department.name' => 'Department',
        'salary' => ['format' => 'currency:USD', 'align' => 'end'],
        'created_at',
    ];
}
```

## Next

- [Columns](columns.md) · [Filters](filters.md) · [Views](views.md)
- [Themes](themes.md) · [Localization](localization.md)
- [Performance](performance.md) · [Security](security.md)

## Publishing the config

Optional — the defaults work — but this is the command people look for:

```bash
php artisan vendor:publish --tag=dynamic-table-config
php artisan vendor:publish --tag=dynamic-table-themes   # to define your own themes
```

See [Installation](installation.md#publishing-all-optional) for every tag.

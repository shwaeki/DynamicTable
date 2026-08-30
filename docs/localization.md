# Localization and RTL

## Bundled languages

English (`en`), Arabic (`ar`), Hebrew (`he`) and Russian (`ru`) ship complete —
every control, operator name, empty state, error and progress message.

Every string goes through Laravel's translator; none are hardcoded.

## Overriding or adding a language

```bash
php artisan dynamic-table:install --lang
```

gives you `lang/vendor/dynamic-table/{locale}/table.php` and `operators.php`.
Copy a folder to add a language.

## Column labels

```php
// lang/ar/dynamic-table/fields.php
return [
    'users' => [
        'name' => 'الاسم',
        'department.name' => 'القسم',
    ],
];
```

Keyed by table key, then field path. Falls back to `$labels`, then to a
humanised path.

## Direction

Direction follows the application locale: `ar`, `he`, `fa`, `ur`, `ps`, `sd` and
`yi` render RTL, everything else LTR.

```php
protected ?string $direction = 'rtl';   // per table
```

```php
'direction' => 'rtl',                   // application-wide, config
```

`null` (the default) means "detect".

## What RTL actually does here

Not just `direction: rtl` on the wrapper. The stylesheet is written with logical
properties throughout — `inset-inline-start`, `margin-inline-start`,
`text-align: start` — so the whole interface mirrors:

- toolbar order and alignment
- header alignment and sort indicators
- the column resize handle and its drag direction
- dropdown and menu anchoring
- the filter builder's nesting indentation
- pagination order
- the column picker's drag affordances

## Dates and numbers

Dates use `translatedFormat()`, so month and day names follow the locale. The
format itself is a translation key, so a language can change the pattern:

```php
'formats' => [
    'date' => 'd M Y',
    'time' => 'H:i',
    'datetime' => 'd M Y H:i',
],
```

Numbers and currency are formatted server-side, which means exports carry the
same values the screen shows.

## Per-request locale

Set the locale as you normally would; the table follows it:

```php
App::setLocale($user->locale);
```

The boot payload carries the resolved locale and direction, so a table rendered
inside a locale-switching layout stays consistent without extra configuration.

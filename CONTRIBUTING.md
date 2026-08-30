# Contributing

Thanks for considering a contribution.

## Getting set up

```bash
git clone https://github.com/shwaeki/DynamicTable.git
cd laravel-dynamictable
composer install
composer test
```

Tests run against SQLite in memory; no database setup is needed.

## Before opening a pull request

```bash
composer lint       # Laravel Pint
composer analyse    # PHPStan / larastan level 5
composer test       # Pest
```

All three must pass. CI runs them against every supported PHP and Laravel
combination.

## What a good pull request looks like

- **One idea per PR.** A bug fix and a refactor in the same diff are hard to
  review and hard to revert.
- **A test that fails before your change.** For a bug fix, the test is the
  bug report. For a feature, it is the specification.
- **Documentation in the same PR.** If the public API changes, `docs/` changes
  with it. An undocumented feature is an unfinished feature here.
- **A demo example** for anything a user can see, in `demo/app/DynamicTables`.
  The demo reads real source files, so it stays in sync by construction.

## The bar for new features

This package's value is its small public API. A feature that adds a property or
a method to `DynamicTable` should:

1. Be impossible or awkward to achieve with what already exists
2. Have a sensible automatic default, so most users never configure it
3. Cost nothing when unused — no query, no JavaScript, no serialised state

If a feature is expensive, it belongs behind a feature flag in
`Support\Feature`, with its JavaScript in its own lazily-imported module.

## Architecture rules

These are non-negotiable, and there are tests enforcing most of them:

- **No N+1.** Relationships are eager loaded from the resolved column list.
- **No unbounded queries.** Never `Model::all()`, never "load them all and
  filter in PHP".
- **Nothing from a request reaches SQL as an identifier.** Field paths resolve
  through the metadata engine; operators come from a closed enum; values are
  bound.
- **One metadata engine.** The filter builder, column picker, import mapper and
  export mapper all read from it. Do not add a second discovery path.
- **Themes are class maps.** Do not add per-theme Blade files for something a
  class name can express.
- **No new runtime dependencies** without a discussion first — especially any
  with a copyleft or commercial licence.

## Coding style

Laravel Pint with the `laravel` preset, `declare(strict_types=1)` everywhere.

Comments should explain *why*, not restate the code. If a piece of code encodes
a decision or a trade-off, say what the alternative was and why it lost.

## Translations

New languages are very welcome. Copy `resources/lang/en` and translate both
`table.php` and `operators.php`. If the language is RTL, add its code to the
list in `DynamicTable::direction()`.

## Reporting bugs

Please include the Laravel and PHP version, the table class (reduced to the
smallest form that reproduces), and what you expected versus what happened.

## Security

Do not open a public issue for a security problem. Email shwaeki98@gmail.com.

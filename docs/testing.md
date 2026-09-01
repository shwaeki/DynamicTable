# Testing

## The package's own suite

```bash
composer test          # pest
composer analyse       # phpstan / larastan level 5
composer lint          # pint
```

The suite covers, in four groups:

| Group | What it proves |
|---|---|
| `tests/Unit` | metadata and type detection, column resolution, feature flags, filter parsing and compilation, query building |
| `tests/Feature` | rendering, endpoints, inline editing, bulk actions, saved views, export and import |
| `tests/Security` | injection attempts, allowlists, scope escapes, authorisation, output escaping |
| `tests/Performance` | query-count budgets and export memory |

Notable assertions worth knowing exist:

- 100 rows with two relationship columns costs **4 queries**, and query count
  does not grow with page size
- a forged sort field is dropped rather than reaching `orderBy`
- an edit cannot reach a record outside `query()`'s scope
- a stale saved view degrades instead of throwing
- exported values cannot be interpreted as spreadsheet formulas

## Testing your own tables

```php
use Shwaeki\DynamicTable\Support\TableRenderer;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Query\QueryEngine;

it('shows only this tenant', function () {
    $table = app(OrdersTable::class);
    $state = TableState::fromArray([], $table);

    expect(app(QueryEngine::class)->build($table, $state)->count())->toBe(3);
});

it('renders', function () {
    expect(app(TableRenderer::class)->render(OrdersTable::class)->toHtml())
        ->toContain('data-dynamic-table');
});
```

Hitting the endpoint directly is often the clearest way to test behaviour:

```php
$this->postJson(route('dynamic-table.data'), [
    'table' => 'orders',
    'state' => ['search' => 'ada', 'sort' => [['field' => 'total', 'direction' => 'desc']]],
])->assertOk()->assertJsonPath('data.total', 2);
```

## Guarding against N+1 in your app

```php
DB::enableQueryLog();
DB::flushQueryLog();

app(TablePayload::class)->data($table, $state);

expect(DB::getQueryLog())->toHaveCount(2 + $relationCount);
```

Or globally, which is the better habit:

```php
Model::preventLazyLoading(! app()->isProduction());
```

## Browser testing

The interactive parts — the filter builder, the column picker, inline editing,
selection, inline editing — deserve browser coverage. With Dusk:

```php
$browser->visit('/users')
    ->waitFor('[data-dynamic-table]')
    ->type('[data-dynamic-table-search]', 'ada')
    ->waitUntilMissing('.dynamic-table-is-loading')
    ->assertSee('ada@example.com')
    ->click('[data-dynamic-table-open="filters"]')
    ->waitFor('.dynamic-table-filter-builder');
```

Stable hooks to select on: `[data-dynamic-table]`, `[data-dynamic-table-search]`,
`[data-dynamic-table-row]`, `[data-dynamic-table-cell]`, `[data-dynamic-table-sort]`, `[data-dynamic-table-open]`,
`[data-dynamic-table-select]`, `[data-dynamic-table-pagination]`, `.dynamic-table-is-loading`. These are treated
as public API for test purposes and will not change without a major release.

To wait for a refresh from JavaScript:

```js
await new Promise(resolve =>
    document.addEventListener('dynamic-table:updated', resolve, { once: true }));
```

## Large datasets

Do not seed a million rows in CI. Seed 1,000, assert the *shape* of the work
(query count, absence of joins, presence of `limit`), and keep genuine
benchmarks in a separate, manually-run suite. That is what
`tests/Performance` does.

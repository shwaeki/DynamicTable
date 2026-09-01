# Performance

Performance is a design constraint here, not a later optimisation. This page
states the budgets, how they are enforced, and what you still have to do
yourself.

## Budgets

| | Budget | Enforced by |
|---|---|---|
| Queries per page render | `2 + one per eager-loaded relation` | `tests/Performance` |
| Query count vs. page size | constant | `tests/Performance` |
| Initial JavaScript | one module, ~14 KB uncompressed | no bundler, no dependencies |
| JavaScript for a plain table | that module only | modules are dynamically imported |
| Boot requests | **zero** | first page rendered server-side |
| Metadata queries per request | 0 when cached | metadata cache |
| Export memory | flat | chunked streaming |

These are shapes, not milliseconds. The repository does not claim a millisecond
number it has not measured on your hardware; run the benchmarks below on yours.

## Where the queries go

Rendering a page of 100 users with `department.name` and `role.name`:

```
1  select count(*) …                     ← pagination total
2  select … from users … limit 100       ← the page
3  select … from departments where id in (…)
4  select … from roles where id in (…)
```

Four queries for 100 rows. Adding rows does not add queries; adding
*relationships* does, one each.

## The N+1 guarantee

Relationship columns are eager loaded from the resolved column list before the
query runs. The row formatter reads relations **only if they were loaded** — an
unloaded relation yields null rather than triggering a lazy load — so a
mis-configured table degrades to a blank cell instead of 101 queries.

If you want the failure to be loud in development:

```php
Model::preventLazyLoading(! app()->isProduction());
```

## Things the package will not do

- `Model::all()` for anything, ever
- Load related records to populate a filter dropdown — options are a paginated,
  searchable `DISTINCT` query
- Count rows to build a filter
- Join to sort by a relationship (it uses a correlated subquery, so no row
  duplication and no `DISTINCT`)
- Load a whole export into memory

## Metadata caching

Schema introspection, cast analysis and relationship discovery are cached for
`config('dynamic-table.cache.ttl')` (24h):

```php
'cache' => ['metadata' => true, 'store' => null, 'ttl' => 86400],
```

Clear it after a migration:

```bash
php artisan dynamic-table:clear
```

Nothing user-specific is ever cached: the cache holds schema shape only.

## What you still have to do

### Index the columns you sort and filter on

The package generates efficient SQL; it cannot create your indexes. Sorting or
filtering on an unindexed column on a large table is slow no matter how the
query is written.

### Think about search on large tables

Global search uses `LIKE '%term%'`, which is portable but cannot use a standard
B-tree index. On tables in the millions of rows:

```php
protected array $searchable = ['email'];       // fewer, indexed columns

// or push search to a real index
public function query(Builder $query): Builder
{
    $term = request('q');

    return $term
        ? $query->whereIn('id', Product::search($term)->keys())
        : $query;
}
```

### Paging and scroll position

Changing page returns you to the top of the table, so you are not left looking
at the middle of the new page. It only scrolls when the table has actually
scrolled out of view — a short table that is fully visible never moves.

```php
'pagination' => ['scroll_on_page' => true],   // false to leave the page alone
```

If your application has a fixed header, set where the table comes to rest:

```css
.dynamic-table { scroll-margin-top: 5rem; }
```

`prefers-reduced-motion` is respected: the jump is instant rather than smooth.

### Deep pagination

`OFFSET 900000` is slow in every database. For very large tables prefer filters
that narrow the set, or a default sort on an indexed column.

## Counting is the thing that stops scaling

A length-aware paginator runs `COUNT(*)` over the filtered set on **every**
request so it can show a total and numbered pages. On a few hundred thousand
rows that is free. On ten million it costs more than fetching the page.

```php
protected string $pagination = 'auto';   // default
// 'length_aware' — always count; real totals and numbered pages
// 'simple'       — never count; previous / next only
```

`auto` asks the database for its own row estimate — which is instant, unlike
`COUNT(*)` — and switches to simple pagination past:

```php
'pagination' => ['count_threshold' => 250000],   // 0 always counts
```

With simple pagination the package fetches one extra row instead of counting,
and shows previous/next rather than numbered pages.

A range with nothing to measure it against is not much use, so the summary still
gives a size when it can:

```
Showing 1–25 of 100,000              counted — exact
Showing 1–25 of about 10,000,000     not counted — the table's own estimate
Showing 1–25                         not counted, and filtered
```

The estimate is only shown while nothing narrows the result set, because it
describes the *table*, not the filtered set. Once a search or filter is applied
the package says what it knows and no more, rather than quoting a number that is
no longer about what you are looking at.

The estimate comes from `information_schema` on MySQL/MariaDB and `pg_class` on
PostgreSQL. SQLite keeps no statistics, so it falls back to a real count — fine,
because SQLite is a development and testing database here.

### Seeing it

The demo ships three examples at 100,000, 1,000,000 and 10,000,000 rows over a
narrow, indexed table:

```bash
cd demo
php artisan dynamic-table:scale 100k    # ~4s
php artisan dynamic-table:scale 1m      # ~35s
php artisan dynamic-table:scale 10m     # a few minutes, and a large file
```

The three table classes are near-identical; what changes with scale is the
pagination strategy and the discipline about which columns are indexed and
searchable.

## Infinite scrolling

```php
protected string $pagination = 'infinite';
```

A presentation choice on top of the same server-side paging: the same endpoint
and the same `LIMIT`, appended instead of replaced. Nothing loads "everything",
and because the pages are stitched together no `COUNT(*)` runs — the footer
reports the range rather than an invented total.

An `IntersectionObserver` watches a sentinel below the last row, so there is no
scroll handler firing on every pixel. Changing the search, a filter or the sort
starts again from the first page.

## Measuring

Turn on the developer panel (never in production):

```php
'performance' => ['panel' => true],
```

It shows the response time, peak memory and which relations were eager loaded,
under each table.

For query-level detail, `DB::listen()` or Telescope. For a repeatable budget
check, `tests/Performance/PerformanceTest.php` is a template you can point at
your own tables.

## Scaling notes

| Rows | What to expect |
|---|---|
| 10,000 | Everything works with no tuning |
| 100,000 | Index your sort and filter columns; keep `searchable` small |
| 1,000,000 | As above, plus: `auto` pagination stops counting, so totals give way to previous/next |
| 10,000,000+ | As above, plus: prefer filtered views over deep pagination, use a real search index, and let large exports queue |

Exports and imports past `config('dynamic-table.excel.queue_threshold')` move to
a queue automatically, with poll-based progress that needs no WebSocket.

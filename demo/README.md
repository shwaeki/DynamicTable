# Laravel DynamicTable — interactive demo

A real Laravel application that installs the real package and renders 34
interactive examples. There is no shadow implementation: every table on every
page is `@dynamicTable(SomeTable::class)`, and the source code shown under each
example is read from the actual file on disk at request time.

## Run it

```bash
cd demo
composer install
php artisan dynamic-table:demo      # migrate + seed (add --fresh to reset)
php artisan serve
```

Open <http://127.0.0.1:8000/dynamic-table/examples>.

The app has two sections, switchable from the header:

| | |
|---|---|
| **Examples** | `/dynamic-table/examples` — interactive tables with their real source |
| **Documentation** | `/dynamic-table/docs` — the full documentation, rendered from the package's own `docs/*.md` |

The documentation is not a copy. It renders the repository's Markdown at request
time, with links between pages rewritten to real URLs, an on-page contents, and
previous/next in reading order — so what you read here is exactly what ships.

The package is linked from the parent directory through a Composer path
repository, so editing `src/` in the package is reflected immediately.

## What is in here

```
app/
├── DynamicTables/     34 table classes — the examples themselves
├── Models/            Company, Department, Role, Category, Product,
│                      Customer, Order, OrderItem, Invoice, User
├── Enums/             OrderStatus (with label()), ProductStatus, UserStatus
├── Support/           the examples index and its entries
└── Providers/         a custom theme registered in one array
database/
├── migrations/        the demo domain
└── seeders/           deterministic data: 240 staff, 180 products,
                       120 customers, 600 orders, invoices and line items
resources/views/       the demo chrome (sidebar, source viewer)
tests/Feature/         asserts every example renders real rows
```

The seed uses a fixed sequence, so every example shows the same rows on every
machine — which is what makes the browser tests meaningful.

## Large datasets

Three examples run over genuinely large tables. They are seeded on demand,
because a ten-million-row SQLite file has no business in a git repository:

```bash
php artisan dynamic-table:scale 100k    # ~4s
php artisan dynamic-table:scale 1m      # ~35s
php artisan dynamic-table:scale 10m     # a few minutes, ~1.5 GB
```

Until you run one, its example page tells you which command to run. Add
`--fresh` to rebuild a table from scratch.

## Try these first

| | |
|---|---|
| [`/basic`](http://127.0.0.1:8000/dynamic-table/examples/basic) | One property. Everything else is discovered. |
| [`/filters`](http://127.0.0.1:8000/dynamic-table/examples/filters) | The nested AND/OR filter builder. |
| [`/spreadsheet`](http://127.0.0.1:8000/dynamic-table/examples/spreadsheet) | Range selection, paste from Excel, batched save. |
| [`/validation`](http://127.0.0.1:8000/dynamic-table/examples/validation) | Break a rule and watch the error land on the cell. |
| [`/performance`](http://127.0.0.1:8000/dynamic-table/examples/performance) | Change the page size; the query count does not move. |
| [`/scale-10m`](http://127.0.0.1:8000/dynamic-table/examples/scale-10m) | Ten million rows, paged without ever counting them. |
| [`/rtl`](http://127.0.0.1:8000/dynamic-table/examples/rtl) | Switch to AR or HE in the header. |
| [`/everything`](http://127.0.0.1:8000/dynamic-table/examples/everything) | Every feature at once — still one small class. |

## Notes

- The demo signs you in as the first seeded user and grants the
  `manage-dynamic-table-system-views` gate, so the saved-views example can
  create both private and shared views. A real application would not do this.
- The developer performance panel is enabled here
  (`DYNAMIC_TABLE_PANEL=true`); it is suppressed in production regardless.
- Bootstrap and Tailwind are both loaded on the page only so the theme examples
  can be compared side by side. A real application loads one, or neither.
- XLSX appears in the export format list only if `openspout/openspout` or
  `phpoffice/phpspreadsheet` is installed; CSV always works.

## Tests

```bash
vendor/bin/pest
```

The suite asserts that every example page loads, renders real rows, points at a
class that actually exists, shows a source file that actually exists, keeps
relationship examples free of N+1 queries, and only exposes the features each
example declares.

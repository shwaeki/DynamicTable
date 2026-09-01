# Export and import

```php
protected array $features = ['export', 'import'];
```

Both directions handle **CSV and XLSX**. CSV needs nothing; XLSX needs a
spreadsheet library, which is deliberately not a dependency of this package —
a data grid should not drag one into an application that only ever exports CSV:

```bash
composer require openspout/openspout            # preferred: constant memory
composer require phpoffice/phpspreadsheet       # also fine (Laravel Excel uses it)
```

openspout is used when both are present. The format list in the export dialog
reflects what is actually installed, so XLSX simply does not appear until one
is — and an XLSX *upload* is refused with a message saying which to install,
rather than failing somewhere inside the parser.

To decline XLSX even where a library is installed — because something else in
the application pulled PhpSpreadsheet in — say so:

```php
// config/dynamic-table.php
'excel' => ['adapter' => 'csv'],
```

## Export

Four scopes:

| Scope | What it exports |
|---|---|
| This page only | the rows on screen — one page's worth |
| Everything matching the current filters | every row the active search, filters and sort produce, however many pages that is |
| Every record, filters ignored | every row the table's `query()` allows |
| Selected records | the current selection |

The first two differ by how much: a page against the whole filtered result. The
labels used to be "Current page" and "Current view", which left the reader to
guess which was which.

Exports respect the **active view**: its visible columns, in their chosen order,
with their formatting. A relationship column exports its displayed value —
`Department` exports `IT`, not `department_id = 4`.

### What an XLSX looks like

By default the export is a **real Excel table** — the one "Format as Table"
makes, with the Table Design ribbon, structured references (`=SUM(Export[Total])`)
and a name — not a grid of values that happens to have a bold first row.

`config('dynamic-table.excel.style')` decides, and takes three kinds of value:

| Value | Result |
|---|---|
| `'TableStyleMedium2'` (default) | a real Excel table in that style |
| `true` | a styled range: dark headings, banded rows, a filter across every column |
| `false` | a bare grid of values |

The style names are Excel's own built-ins — `TableStyleLight1`–`21`,
`TableStyleMedium1`–`28`, `TableStyleDark1`–`11`. An unrecognised name **throws**
rather than being quietly ignored, on the same reasoning as an unknown feature
name: a style Excel drops on the floor is a style the author thinks is applied.

Whatever the mode, the file also gets:

- the heading row **frozen**, so a long export never loses track of which column
  is which;
- **columns sized to their content**, measured as the rows stream past — one
  integer per column, so a million-row export measures itself for the price of
  the headings;
- the sheet tab named after the table rather than `Sheet1`;
- and the sheet set **right-to-left when the table is**, which Excel cannot work
  out from the content.

Both drivers produce the same file. openspout has no notion of a table, so the
four OOXML parts one needs are added to the finished archive; PhpSpreadsheet
builds one natively. `config('dynamic-table.excel.adapter')` pins the driver
(`auto`, `openspout`, `phpspreadsheet`, `csv`) when it matters.

CSV is untouched by all of it. It is a data format, and the round trip back
through import depends on it staying one.

Colours in the `true` mode are literal rather than taken from the package's CSS
tokens: Excel has no idea what a custom property is, and the file has to be
legible on a printer.

### Which format the dialogs open on

XLSX, where a spreadsheet library is installed — a spreadsheet is what people do
with an export, and the file arrives filterable and readable instead of as raw
commas. That covers the export dialog, the import template, and the format each
offers first. `config('dynamic-table.excel.default_format')` set to `csv` puts
CSV back in front; with no spreadsheet library installed, CSV is the only option
either way.

### Large exports

Rows are read in chunks (`config('dynamic-table.excel.chunk')`, default 1000)
and streamed, so memory is flat regardless of size. Past
`config('dynamic-table.excel.queue_threshold')` (default 5000 rows) the export
is queued instead, and the UI polls for progress and then downloads the result.

Progress polling is deliberate: it works in applications with no broadcasting
configured, which is most of them.

### CSV safety

A value starting with `=`, `+`, `-`, `@` or a control character is prefixed with
an apostrophe so a spreadsheet cannot be tricked into evaluating exported data
as a formula. A UTF-8 BOM is written so Excel opens Arabic, Hebrew and Russian
files correctly.

## Import

The flow is: **choose a file → match the columns → check and import**, and the
panel shows all three from the start rather than revealing them one at a time,
so it is clear up front what the import will ask for.

Analysis returns the file's headings, the first five rows, a row count and a
suggested mapping produced by matching headings against column labels and paths.
You can override any mapping, or set a column to "ignore".

### Modes

| Mode | Behaviour |
|---|---|
| Create | insert every row |
| Update | only update rows that match an existing record |
| Create + update | upsert |

For update/upsert, choose the field to match on. It has to be a field the file
supplies: matching on a column the mapping does not fill finds nothing every
time, so update would skip every row and upsert would insert a duplicate of
every one — which, on a file exported from the same table, fails on the unique
key while "Create and update" is selected. The panel defaults to a mapped
field and refuses to run otherwise, and so does the server.

Importing an export back in **Create** mode fails every row on the table's own
unique key, because those records already exist. That reads as "A record with
this Reference already exists. Choose 'Create and update' to update it instead",
against the column that clashed — not as the raw statement the database
rejected.

### Values

An export can be imported straight back. The file holds what the reader saw, so
the importer reads it that way: `$1,812.43` is 1812.43, `12.5%` is 12.5, `13 Feb
2026 09:32` is that moment, `Yes` — or `نعم` — is true, and a status reads by its
label as well as its stored value. A date is parsed with the column's own
`format` pattern, so `dd/mm/yyyy` cannot come back with the day and month
swapped, and the export's CSV-safety apostrophe is removed again.

Two deliberate exceptions:

- A `bytes` column does not reverse. `1.5 MB` has already lost the digits that
  made it 1,572,000, and a plausible wrong number is worse than a rejection.
- A comma is only read as a thousands separator where `number_format` would have
  written one. `1,5` from a European spreadsheet is rejected rather than
  imported as fifteen.

Anything that still does not parse is left as it stands, so it fails validation
by name instead of arriving as a zero.

### Required columns

A column that is `NOT NULL` in the schema has to be mapped before the file can
be imported. The panel marks the rows that fill one, names any that are still
missing under the mapping, and keeps **Start import** disabled until there are
none; the server checks the same thing, so an import driven from code cannot
skip it either. Without that the rows all passed validation — a column that is
not in the mapping has no rules to fail — and then died one at a time on a
constraint the mapping never mentioned.

The primary key is exempt: it is `NOT NULL` too, and the database fills it in.
**Update** mode is exempt as a whole, because it only writes the columns it is
given and leaves the rest of the record alone.

A `NOT NULL` column with a database default is still treated as required, which
is stricter than it has to be — the metadata does not carry defaults.

### Relationships

A column mapped to `department.name` is resolved to `department_id` by looking
up the related record. Lookups are cached per import, so a 50,000-row file with
20 departments performs 20 lookups, not 50,000. A value with no match fails that
row with a clear message rather than silently importing a null.

### Validation and error reporting

Each row is validated with the table's `rules()`, falling back to rules derived
from the column metadata. Rows are processed in **chunked transactions**: a bad
row fails on its own, a failed chunk rolls back without discarding the file, and
nothing is ever wrapped in one enormous transaction.

The response summarises created / updated / skipped / failed and lists the first
50 rejections inline, under the rows they came from.

Every rejection — not just the first fifty — also goes into a CSV report on the
transfer disk, offered in the dialog as **Error report**: one line per failed
field, with the line number, the field, the message and the offending row, so a
large file can be corrected in the file rather than from the screen.

The report is served by `POST {prefix}/import/errors`, and reaching it takes
four things: the table resolves from the registry, `import` is enabled on it,
the viewer passes the `import` ability, and the key carries an HMAC signed with
that table's key. That last part means a report can only be fetched back
through the table that produced it — a viewer who may import one table cannot
read another's rejected rows, which are that table's data.

Reports accumulate like exports do. If you keep them on a disk that is not
swept, prune the `import-errors` folder on a schedule.

Past the queue threshold the import is queued with the same poll-based progress
as exports.

### Template

"Download template" produces a file with the importable column headings and a
hint row — expected date format, the enum's allowed values, which columns are
required.

### When something is refused

Every gate in the transfer routes answers with a reason, translated: the file
was too large or the wrong type, the upload has already been used, a column is
unmapped, the format is one this application cannot write, the viewer may not
import. A database failure is reported from the driver's own message rather
than from the failed statement, which used to carry every binding and the path
to the database file into the error report.

## Events

`ExportStarted`, `ExportCompleted`, `ImportStarted`, `ImportCompleted` and
`ImportFailed` all extend `TransferEvent`, so a listener can type-hint the base
class to observe every transfer.

## Configuration

```php
'excel' => [
    'queue_threshold' => 5000,   // 0 disables queueing entirely
    'chunk' => 1000,
    'disk' => null,              // default filesystem disk
    'directory' => 'dynamic-table',
    'queue' => null,             // queue name for the jobs
],
```

## Authorisation

Export requires the `export` ability, import requires `import` — resolved
through `authorize()` and then the model policy (`viewAny` and `create`
respectively). The upload path is protected by an HMAC token issued during
analysis, so the importer cannot be pointed at an arbitrary file.

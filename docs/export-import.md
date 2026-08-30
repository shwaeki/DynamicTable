# Export and import

```php
protected array $features = ['export', 'import'];
```

CSV works out of the box with no extra dependency. For XLSX, install one of:

```bash
composer require openspout/openspout            # preferred: constant memory
composer require phpoffice/phpspreadsheet       # also fine (Laravel Excel uses it)
```

The format list offered in the UI reflects what is actually installed.

## Export

Four scopes:

| Scope | What it exports |
|---|---|
| Current page | exactly what is on screen |
| Current view | every row matching the active search, filters and sort |
| All records | every row the table's `query()` allows, ignoring filters |
| Selected | the current selection |

Exports respect the **active view**: its visible columns, in their chosen order,
with their formatting. A relationship column exports its displayed value —
`Department` exports `IT`, not `department_id = 4`.

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

The flow is: **upload → analyse → map → preview → run**.

Analysis returns the file's headings, the first five rows, a row count and a
suggested mapping produced by matching headings against column labels and paths.
You can override any mapping, or set a column to "ignore".

### Modes

| Mode | Behaviour |
|---|---|
| Create | insert every row |
| Update | only update rows that match an existing record |
| Create + update | upsert |

For update/upsert, choose the field to match on (defaults to the primary key).

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

The response summarises created / updated / skipped / failed, includes the first
50 errors inline, and writes a downloadable CSV error report listing every
rejected line, the field, the message and the offending row.

Past the queue threshold the import is queued with the same poll-based progress
as exports.

### Template

"Download template" produces a file with the importable column headings and a
hint row — expected date format, the enum's allowed values, which columns are
required.

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

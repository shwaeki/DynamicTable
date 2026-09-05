<?php

namespace Shwaeki\DynamicTable\Modules\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\Columns\ColumnDefinition;
use Shwaeki\DynamicTable\Contracts\SpreadsheetReader;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Events\ImportCompleted;
use Shwaeki\DynamicTable\Events\ImportFailed;
use Shwaeki\DynamicTable\Events\ImportStarted;
use Shwaeki\DynamicTable\Metadata\FieldType;
use Shwaeki\DynamicTable\Modules\Export\Writers\CsvWriter;
use Shwaeki\DynamicTable\Modules\Export\Writers\XlsxWriter;
use Shwaeki\DynamicTable\Modules\Import\Readers\CsvReader;
use Shwaeki\DynamicTable\Modules\Import\Readers\XlsxReader;
use Shwaeki\DynamicTable\Support\DateFormat;
use Shwaeki\DynamicTable\Support\TransferProgress;
use Throwable;

/**
 * Chunked, validating importer.
 *
 * Rows are read as a stream and processed in transactional chunks: a bad row
 * fails on its own without taking a whole ten-thousand-row file with it, and
 * nothing is ever accumulated in memory beyond the current chunk.
 */
class ImportManager
{
    /** @var array<string, array<string, int|string|null>> */
    protected array $relationCache = [];

    public function readerFor(string $path): SpreadsheetReader
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['xlsx', 'xls'], true)) {
            return new CsvReader;
        }

        // The upload rules accept a spreadsheet, so a file can arrive here that
        // nothing installed can open. Said plainly and early, as a 422 with a
        // fix in it, rather than as a parse failure from inside the reader.
        abort_unless(
            XlsxReader::isAvailable(),
            422,
            'This application cannot read '.$extension.' files. '
            .'Install openspout/openspout or phpoffice/phpspreadsheet, or import a CSV instead.',
        );

        return new XlsxReader;
    }

    /**
     * Inspect an uploaded file: headings, a preview, and a suggested mapping.
     *
     * @return array<string, mixed>
     */
    public function analyze(DynamicTable $table, string $path): array
    {
        $reader = $this->readerFor($path);
        $headings = $reader->headings($path);

        $preview = [];
        $index = 0;

        foreach ($reader->rows($path) as $row) {
            $preview[] = array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row);

            if (++$index >= 5) {
                break;
            }
        }

        return [
            'headings' => $headings,
            'preview' => $preview,
            'total' => $reader->countRows($path),
            'mapping' => $this->suggestMapping($table, $headings),
            'fields' => $this->importableFields($table),
        ];
    }

    /**
     * @return array<string, array<string, mixed>> keyed by column key
     */
    public function importableFields(DynamicTable $table): array
    {
        $fields = [];

        foreach ($table->resolvedColumns() as $key => $column) {
            if ($column->isComputed()) {
                continue;
            }

            if ($column->isRelational() && ! $this->isResolvableRelation($table, $column)) {
                continue;
            }

            $fields[$key] = [
                'key' => $key,
                'path' => $column->path(),
                'label' => $column->label,
                'type' => $column->type->value,

                /*
                 * NOT NULL in the schema means the import has to supply it.
                 * The primary key is the exception: it is NOT NULL too, and
                 * the database is the one that fills it in.
                 *
                 * A NOT NULL column with a database default is required here
                 * as well, which is stricter than it has to be — the metadata
                 * does not carry defaults, and asking for a value that would
                 * have been filled in is a smaller problem than an insert that
                 * fails on a constraint the mapping never mentioned.
                 */
                'required' => ! $column->field->nullable && ! $column->field->primary,
                'options' => $column->field->options ?: null,
            ];
        }

        return $fields;
    }

    /**
     * The required fields this mapping does not fill, by label.
     *
     * Without this the file simply arrived short: every row passed validation,
     * because a column that is not in the mapping has no rules to fail, and
     * then every insert died on a NOT NULL constraint one row at a time. The
     * mapping is the place to say it, once, before anything is written.
     *
     * @param  array<int, string|null>  $mapping  heading index => column key
     * @return list<string>
     */
    public function missingRequired(DynamicTable $table, array $mapping): array
    {
        $mapped = array_filter(array_values($mapping), static fn (mixed $key): bool => is_string($key) && $key !== '');
        $missing = [];

        foreach ($this->importableFields($table) as $key => $field) {
            if ($field['required'] && ! in_array($key, $mapped, true)) {
                $missing[] = (string) $field['label'];
            }
        }

        return $missing;
    }

    /**
     * The field update and upsert will look existing records up by.
     *
     * Resolved exactly as importRow() resolves it, default included, because a
     * check that disagrees with the code it is guarding is worse than no check.
     *
     * @return array<string, mixed>|null
     */
    public function matchField(DynamicTable $table, ?string $matchBy): ?array
    {
        $key = $matchBy !== null && $matchBy !== '' ? $matchBy : $table->metadata()->keyName;

        foreach ($this->importableFields($table) as $field) {
            if ($field['path'] === $key || $field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The label of the match field when the file has no column feeding it,
     * or null when the mapping is sound.
     *
     * Matching on a column the file does not supply cannot find anything: every
     * lookup returns nothing, so update skips every row and upsert inserts
     * every row — which, on a file exported from the same table, means a
     * duplicate-key failure on all of them while the panel says "Create and
     * update" is selected. The mapping is where to say so.
     *
     * @param  array<int, string|null>  $mapping  heading index => column key
     */
    public function matchUnmapped(DynamicTable $table, array $mapping, ?string $matchBy): ?string
    {
        $field = $this->matchField($table, $matchBy);

        if ($field === null) {
            return $matchBy !== null && $matchBy !== '' ? $matchBy : $table->metadata()->keyName;
        }

        $mapped = array_filter(array_values($mapping), static fn (mixed $key): bool => is_string($key) && $key !== '');

        return in_array($field['key'], $mapped, true) ? null : (string) $field['label'];
    }

    /**
     * @param  list<string>  $headings
     * @return array<int, string|null> heading index => column key
     */
    public function suggestMapping(DynamicTable $table, array $headings): array
    {
        $candidates = [];

        foreach ($this->importableFields($table) as $key => $field) {
            $candidates[Str::slug($field['label'])] = $key;
            $candidates[Str::slug($field['path'])] = $key;
            $candidates[Str::slug(str_replace('_', '-', $field['path']))] = $key;
        }

        $mapping = [];

        foreach ($headings as $index => $heading) {
            $slug = Str::slug((string) $heading);
            $mapping[$index] = $candidates[$slug] ?? null;
        }

        return $mapping;
    }

    /**
     * Run the import.
     *
     * @param  array<int, string|null>  $mapping  heading index => column key
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(
        DynamicTable $table,
        string $path,
        array $mapping,
        string $mode = 'create',
        array $options = [],
        ?string $progressId = null,
    ): array {
        $mode = in_array($mode, ['create', 'update', 'upsert'], true) ? $mode : 'create';
        $matchKey = isset($options['matchBy']) ? (string) $options['matchBy'] : null;

        /*
         * A dry run is the real import, rolled back.
         *
         * Not a simulation of it: the same validation, the same casting, the
         * same relation lookups, the same unique constraints, the same
         * database. A preview that ran different code would be a preview of
         * different code, and the row it disagreed about would be exactly the
         * one somebody cared about.
         *
         * What it cannot undo is what leaves the database: an observer that
         * queues a job, sends a mail, or writes to an external service still
         * did so. That is documented rather than guessed at.
         */
        $dry = (bool) ($options['dryRun'] ?? false);

        if ($dry) {
            $connection = DB::connection($table->newModel()->getConnectionName());
            $connection->beginTransaction();

            try {
                return $this->execute($table, $path, $mapping, $mode, $matchKey, $progressId, true);
            } finally {
                $connection->rollBack();
            }
        }

        return $this->execute($table, $path, $mapping, $mode, $matchKey, $progressId, false);
    }

    /**
     * The import itself, with the transaction decision already made.
     *
     * @param  array<int, string|null>  $mapping
     * @return array<string, mixed>
     */
    protected function execute(
        DynamicTable $table,
        string $path,
        array $mapping,
        string $mode,
        ?string $matchKey,
        ?string $progressId,
        bool $dry,
    ): array {

        if ($mode !== 'create' && $matchKey === null) {
            $matchKey = $table->metadata()->keyName;
        }

        $reader = $this->readerFor($path);

        $chunkSize = max(50, (int) config('dynamic-table.excel.chunk', 1000));

        $columns = $table->resolvedColumns();
        $declaredRules = $table->rules();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        // A dry run announces nothing. A listener that reacted to it would be
        // reacting to an import that is about to be undone.
        if (! $dry) {
            event(new ImportStarted($table->key(), $progressId ?? 'sync', ['mode' => $mode]));
        }

        $buffer = [];
        $lineNumber = 1; // heading row

        foreach ($reader->rows($path) as $row) {
            $lineNumber++;
            $buffer[] = [$lineNumber, $row];

            if (count($buffer) >= $chunkSize) {
                $result = $this->flushChunk($table, $buffer, $mapping, $mode, $matchKey, $columns, $declaredRules, $progressId);
                $created += $result['created'];
                $updated += $result['updated'];
                $skipped += $result['skipped'];
                $errors = array_merge($errors, $result['errors']);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $result = $this->flushChunk($table, $buffer, $mapping, $mode, $matchKey, $columns, $declaredRules, $progressId);
            $created += $result['created'];
            $updated += $result['updated'];
            $skipped += $result['skipped'];
            $errors = array_merge($errors, $result['errors']);
        }

        $report = $errors === [] ? null : $this->writeErrorReport($table, $errors);

        $summary = [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => count($errors),
            'errors' => array_slice(array_map(
                static fn (array $error): array => ['line' => $error['line'], 'errors' => $error['errors']],
                $errors,
            ), 0, 50),
            'report' => $report,
        ];

        if ($dry) {
            // Said plainly in the payload rather than inferred from a flag the
            // caller passed: the panel draws a different sentence for it.
            return $summary + ['dry' => true];
        }

        event($errors === [] || $created + $updated > 0
            ? new ImportCompleted($table->key(), $progressId ?? 'sync', $summary)
            : new ImportFailed($table->key(), $progressId ?? 'sync', $summary));

        return $summary;
    }

    /**
     * Import one chunk inside its own transaction.
     *
     * Chunk-level transactions are the deliberate middle ground: a failed
     * chunk rolls back without discarding the whole file, and a million-row
     * import is never wrapped in one enormous transaction.
     *
     * @param  list<array{0: int, 1: list<mixed>}>  $rows
     * @param  array<int, string|null>  $mapping
     * @param  array<string, ColumnDefinition>  $columns
     * @param  array<string, mixed>  $declaredRules
     * @return array{created: int, updated: int, skipped: int, errors: list<array{line: int, errors: array<string, list<string>>, row: list<mixed>}>}
     */
    protected function flushChunk(
        DynamicTable $table,
        array $rows,
        array $mapping,
        string $mode,
        ?string $matchKey,
        array $columns,
        array $declaredRules,
        ?string $progressId,
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        try {
            DB::connection($table->newModel()->getConnectionName())->transaction(function () use (
                $rows, &$created, &$updated, &$skipped, &$errors,
                $table, $mapping, $mode, $matchKey, $columns, $declaredRules,
            ): void {
                foreach ($rows as [$line, $row]) {
                    $outcome = $this->importRow(
                        $table, $row, $mapping, $mode, $matchKey, $columns, $declaredRules,
                    );

                    match ($outcome['status']) {
                        'created' => $created++,
                        'updated' => $updated++,
                        'skipped' => $skipped++,
                        default => null,
                    };

                    if ($outcome['status'] === 'error') {
                        $errors[] = ['line' => $line, 'errors' => $outcome['errors'] ?? [], 'row' => $row];
                    }
                }
            });
        } catch (Throwable $exception) {
            $created = $updated = $skipped = 0;
            $errors = [];

            $reason = $this->databaseError($exception, $columns);

            foreach ($rows as [$line, $row]) {
                $errors[] = ['line' => $line, 'errors' => $reason, 'row' => $row];
            }
        }

        if ($progressId !== null) {
            TransferProgress::advance($progressId, count($rows));
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<int, string|null>  $mapping
     * @param  array<string, ColumnDefinition>  $columns
     * @param  array<string, mixed>  $declaredRules
     * @return array{status: string, errors?: array<string, list<string>>}
     */
    protected function importRow(
        DynamicTable $table,
        array $row,
        array $mapping,
        string $mode,
        ?string $matchKey,
        array $columns,
        array $declaredRules,
    ): array {
        $attributes = [];
        $rules = [];
        $matchValue = null;

        foreach ($mapping as $index => $columnKey) {
            if ($columnKey === null || ! isset($columns[$columnKey])) {
                continue;
            }

            $column = $columns[$columnKey];
            $value = $row[$index] ?? null;
            $value = is_string($value) ? $this->unescape(trim($value)) : $value;

            if ($column->path() === $matchKey || $columnKey === $matchKey) {
                $matchValue = $value;
            }

            if ($column->isRelational()) {
                $resolved = $this->resolveRelationValue($table, $column, $value);

                if ($resolved === false) {
                    return [
                        'status' => 'error',
                        'errors' => [$columnKey => [__('dynamic-table::table.errors.unknown_relation', [
                            'value' => (string) $value,
                            'field' => $column->label,
                        ])]],
                    ];
                }

                [$foreignKey, $foreignValue] = $resolved;
                $attributes[$foreignKey] = $foreignValue;

                continue;
            }

            $name = (string) ($column->field->column ?? $column->field->name);
            $attributes[$name] = $this->cast($value, $column);
            $rules[$name] = $declaredRules[$column->path()] ?? $declaredRules[$name] ?? $this->autoRules($column);
        }

        if ($attributes === []) {
            return ['status' => 'skipped'];
        }

        $validator = Validator::make($attributes, $rules, $table->validationMessages());

        if ($validator->fails()) {
            return ['status' => 'error', 'errors' => $validator->errors()->messages()];
        }

        $model = $table->newModel();
        $existing = null;

        if ($mode !== 'create' && $matchKey !== null && $matchValue !== null && $matchValue !== '') {
            $matchColumn = $columns[str_replace('.', '__', $matchKey)]->field->column ?? $matchKey;
            $existing = $model->newQuery()->where($matchColumn, $matchValue)->first();
        }

        if ($existing === null && $mode === 'update') {
            return ['status' => 'skipped'];
        }

        try {
            if ($existing instanceof Model) {
                $existing->forceFill($attributes)->save();

                return ['status' => 'updated'];
            }

            $model->forceFill($attributes)->save();

            return ['status' => 'created'];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'errors' => $this->databaseError($exception, $columns)];
        }
    }

    /**
     * What the database refused, in words.
     *
     * A QueryException stringifies to the whole failed statement — every
     * binding, and the absolute path to the database file. That went straight
     * into the failed-rows list and the downloadable error report, where it
     * told the reader nothing they could act on and rather more about the
     * server than they needed. The wrapped driver exception says the same
     * thing without the statement, so that is what is read.
     *
     * A duplicate key is the one worth naming: it is what happens when an
     * export is imported back in Create mode, and the fix is a different mode
     * rather than a different file.
     *
     * @param  array<string, ColumnDefinition>  $columns
     * @return array<string, list<string>>
     */
    protected function databaseError(Throwable $exception, array $columns): array
    {
        $message = $exception instanceof QueryException && $exception->getPrevious() !== null
            ? $exception->getPrevious()->getMessage()
            : $exception->getMessage();

        if (preg_match('/unique|duplicate/i', $message) !== 1) {
            return ['_' => [(string) __('dynamic-table::table.errors.database', ['message' => $message])]];
        }

        $mode = (string) __('dynamic-table::table.import.mode_upsert');
        $column = $this->offendingColumn($message, $columns);

        if ($column === null) {
            return ['_' => [(string) __('dynamic-table::table.errors.duplicate_row', ['mode' => $mode])]];
        }

        return [(string) ($column->field->column ?? $column->field->name) => [
            (string) __('dynamic-table::table.errors.duplicate', ['field' => $column->label, 'mode' => $mode]),
        ]];
    }

    /**
     * The column a unique violation is about.
     *
     * Every driver words it differently — "orders.reference", "for key
     * 'orders_reference_unique'", "Key (reference)=(...)" — so rather than
     * four patterns that each go stale on their own schedule, the message is
     * searched for the names of the columns being imported. Longest first, so
     * a message naming reference_id is not credited to reference.
     *
     * @param  array<string, ColumnDefinition>  $columns
     */
    protected function offendingColumn(string $message, array $columns): ?ColumnDefinition
    {
        $candidates = [];

        foreach ($columns as $column) {
            if ($column->isComputed() || $column->isRelational()) {
                continue;
            }

            $candidates[(string) ($column->field->column ?? $column->field->name)] = $column;
        }

        uksort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($candidates as $name => $column) {
            if (preg_match('/\b'.preg_quote((string) $name, '/').'\b/i', $message) === 1) {
                return $column;
            }
        }

        return null;
    }

    protected function isResolvableRelation(DynamicTable $table, ColumnDefinition $column): bool
    {
        $path = $column->field->relationPath;

        if (count($path) !== 1) {
            return false;
        }

        $relation = $table->metadata()->relation($path[0]);

        return $relation?->type === 'BelongsTo' && $relation->foreignKey !== null;
    }

    /**
     * Turn "IT" in a Department column into the matching department_id.
     *
     * @return array{0: string, 1: mixed}|false
     */
    protected function resolveRelationValue(DynamicTable $table, ColumnDefinition $column, mixed $value): array|false
    {
        if (! $this->isResolvableRelation($table, $column)) {
            return false;
        }

        $relationName = $column->field->relationPath[0];
        $relation = $table->metadata()->relation($relationName);
        $foreignKey = (string) $relation->foreignKey;

        if ($value === null || $value === '') {
            return [$foreignKey, null];
        }

        $lookupColumn = (string) ($column->field->column ?? $column->field->name);
        $cacheKey = $relationName.'|'.$lookupColumn;

        if (! isset($this->relationCache[$cacheKey][(string) $value])) {
            $relatedClass = (string) $relation->relatedModel;
            /** @var Model $related */
            $related = new $relatedClass;

            $found = $related->newQuery()
                ->where($lookupColumn, $value)
                ->value($relation->ownerKey ?? $related->getKeyName());

            $this->relationCache[$cacheKey][(string) $value] = $found;
        }

        $id = $this->relationCache[$cacheKey][(string) $value];

        return $id === null ? false : [$foreignKey, $id];
    }

    /**
     * A cell of the file, as the column's own type.
     *
     * The importer accepts what the exporter writes. Export a table and import
     * the file back and the rows have to survive the trip: the file holds what
     * the reader saw — "$1,812.43", "13 Feb 2026 09:32", "Yes" — not what the
     * database holds, because the file is also something people read. Matching
     * an enum by its label already worked this way; money, dates and booleans
     * did not, so re-importing an untouched export failed on every one of them
     * with "The total must be a number".
     *
     * Anything that still does not parse is handed on unchanged, so validation
     * rejects it by name rather than this quietly turning it into a zero.
     */
    protected function cast(mixed $value, ColumnDefinition $column): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($column->type) {
            FieldType::Boolean => $this->matchBoolean($value),
            FieldType::Integer => is_numeric($n = $this->unformat($value)) ? (int) $n : $value,
            FieldType::Decimal => is_numeric($n = $this->unformat($value)) ? (float) $n : $value,
            FieldType::Date, FieldType::DateTime, FieldType::Time => $this->parseDate($value, $column),
            FieldType::Enum => $this->matchEnum($value, $column),
            default => $value,
        };
    }

    /**
     * Undo CsvWriter::sanitize().
     *
     * A value starting with one of the characters a spreadsheet would treat as
     * a formula is exported behind an apostrophe, so opening the file cannot
     * run it. Re-importing kept the apostrophe: phone numbers came back as
     * "'+44 20 2120" and a negative total as "'-5.00".
     *
     * Only that exact escape is undone — an apostrophe in front of anything
     * else is somebody's data and stays.
     */
    protected function unescape(string $value): string
    {
        return strlen($value) > 1
            && $value[0] === "'"
            && str_contains("=+-@\t\r", $value[1])
                ? substr($value, 1)
                : $value;
    }

    /**
     * Undo the presentation RowFormatter puts on a number.
     *
     * It writes number_format($value, $decimals, '.', ','), optionally behind a
     * currency symbol or an ISO code and optionally in front of a "%", so this
     * strips exactly those and nothing else.
     *
     * The comma is only dropped where number_format would have put one — every
     * three digits. "1,5" from a European spreadsheet therefore stays "1,5" and
     * fails validation, rather than silently importing as fifteen.
     *
     * A "bytes" column is deliberately not reversed: "1.5 MB" has already lost
     * the digits that made it 1,572,000, and a plausible wrong number is worse
     * than an honest rejection.
     */
    protected function unformat(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Every kind of space, the non-breaking ones included: a thousands
        // separator can arrive as one, and so can padding around a symbol.
        $clean = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);

        if (str_ends_with($clean, '%')) {
            $clean = substr($clean, 0, -1);
        }

        // The symbol or code in front of the digits — "$", "€", "AED".
        $clean = (string) preg_replace('/^[^\d+\-.]+/u', '', $clean);

        if (preg_match('/^[+-]?\d{1,3}(,\d{3})+(\.\d+)?$/', $clean) === 1) {
            $clean = str_replace(',', '', $clean);
        }

        return is_numeric($clean) ? $clean : $value;
    }

    /**
     * A date read back with the pattern it was written in.
     *
     * Carbon::parse() copes with "13 Feb 2026 09:32" and is what this used to
     * fall through to, but it is wrong twice over: it reads "13/02/2026" as a
     * month of thirteen and, worse, reads "03/02/2026" as the third of
     * February — a d/m/Y export silently imported with day and month swapped.
     * It also knows nothing of "13 فبراير 2026", which is what that same export
     * writes in Arabic.
     *
     * So the column's own pattern is tried first, in the current locale, and
     * parse() stays the fallback for a file somebody wrote by hand — or for the
     * import template, which asks for a plain YYYY-MM-DD.
     */
    protected function parseDate(mixed $value, ColumnDefinition $column): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $kind = match ($column->type) {
            FieldType::Date => 'date',
            FieldType::Time => 'time',
            default => 'datetime',
        };

        $pattern = $this->datePattern($column) ?? DateFormat::defaultPattern($kind);
        $parsed = null;

        try {
            /*
             * The leading "!" zeroes everything the pattern does not mention.
             * Without it PHP fills the gaps from the system clock, so a
             * dd/mm/yyyy column imported at twenty past four stored every row
             * as 16:20 on its own date.
             */
            $parsed = Carbon::createFromLocaleFormat(
                '!'.DateFormat::toPhp($pattern),
                (string) app()->getLocale(),
                $value,
            );
        } catch (Throwable) {
            $parsed = null;
        }

        if (! $parsed instanceof Carbon) {
            try {
                $parsed = Carbon::parse($value);
            } catch (Throwable) {
                return $value;
            }
        }

        return $parsed->format(match ($kind) {
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            default => 'Y-m-d H:i:s',
        });
    }

    /** The pattern a column's own format names, if it names one. */
    protected function datePattern(ColumnDefinition $column): ?string
    {
        if ($column->format === null) {
            return null;
        }

        [$name, $argument] = array_pad(explode(':', $column->format, 2), 2, null);

        if (in_array($name, ['date', 'datetime', 'time'], true)) {
            return $argument;
        }

        /*
         * A date column may name its pattern bare — 'dd/mm/yyyy' — the way the
         * formatter accepts it. A bare word is a format *name* rather than a
         * pattern, however much looksLikePattern() likes its letters: every
         * character of "since" happens to be a valid date() code.
         */
        return DateFormat::looksLikePattern($column->format)
            && preg_match('/[^a-z]/i', $column->format) === 1
                ? $column->format
                : null;
    }

    /**
     * The exporter writes a boolean as the translated Yes/No the reader saw, so
     * those come first; filter_var still handles 1/0, true/false and on/off for
     * a file somebody wrote by hand.
     */
    protected function matchBoolean(mixed $value): ?bool
    {
        if (is_string($value)) {
            $text = mb_strtolower(trim($value));

            foreach (['yes' => true, 'no' => false] as $key => $result) {
                if ($text === mb_strtolower((string) __('dynamic-table::table.'.$key))) {
                    return $result;
                }
            }
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    protected function matchEnum(mixed $value, ColumnDefinition $column): mixed
    {
        foreach ($column->field->options as $option) {
            if (strcasecmp((string) $option['value'], (string) $value) === 0
                || strcasecmp((string) $option['label'], (string) $value) === 0) {
                return $option['value'];
            }
        }

        return $value;
    }

    /** @return list<string> */
    protected function autoRules(ColumnDefinition $column): array
    {
        $rules = [$column->field->nullable ? 'nullable' : 'required'];

        $rules[] = match ($column->type) {
            FieldType::Integer => 'integer',
            FieldType::Decimal => 'numeric',
            FieldType::Boolean => 'boolean',
            FieldType::Date, FieldType::DateTime => 'date',
            FieldType::Email => 'email',
            FieldType::Url => 'url',
            default => 'string',
        };

        if ($column->type === FieldType::Enum && $column->field->options !== []) {
            $rules[] = 'in:'.implode(',', array_column($column->field->options, 'value'));
        }

        if ($column->field->length !== null && $column->type->isTextual()) {
            $rules[] = 'max:'.$column->field->length;
        }

        return $rules;
    }

    /**
     * A CSV listing every rejected row and why it failed, on the same disk as
     * exports, and returned as a storage key rather than a bare filename.
     *
     * A queued import runs on a worker that may not share a filesystem with the
     * web process, so writing this beside the code with mkdir() left a report
     * the downloader could not reach. It goes through the configured disk for
     * the same reason an export does.
     *
     * @param  list<array{line: int, errors: array<string, list<string>>, row: list<mixed>}>  $errors
     * @return string the storage key, relative to the configured disk
     */
    protected function writeErrorReport(DynamicTable $table, array $errors): string
    {
        $key = self::reportDirectory()
            .'/'.Str::slug($table->key()).'-import-errors-'
            .now()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.csv';

        // The writers are streaming and take a path, so the rows are built
        // locally and the finished file is handed to the disk in one go.
        $path = tempnam(sys_get_temp_dir(), 'dt-import-errors');

        $writer = new CsvWriter;
        $writer->open($path);
        $writer->writeHeadings(['Line', 'Field', 'Error', 'Value']);

        foreach ($errors as $error) {
            foreach ($error['errors'] as $field => $messages) {
                foreach ($messages as $message) {
                    $writer->writeRow([
                        $error['line'],
                        $field,
                        $message,
                        Str::limit(implode(' | ', array_map(
                            static fn (mixed $v): string => (string) $v,
                            $error['row'],
                        )), 200),
                    ]);
                }
            }
        }

        $writer->close();

        $stream = fopen($path, 'rb');
        Storage::disk(config('dynamic-table.excel.disk'))->put($key, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        @unlink($path);

        return $key;
    }

    /**
     * Where error reports live.
     *
     * Its own folder under the transfer directory, so a retention policy can
     * treat reports differently from exports — they are diagnostic and short
     * lived, exports are the thing the user asked for.
     */
    public static function reportDirectory(): string
    {
        return trim((string) config('dynamic-table.excel.directory', 'dynamic-table'), '/').'/import-errors';
    }

    /** Build the import template file and return its local path. */
    public function template(DynamicTable $table, string $format = 'xlsx'): string
    {
        $fields = $this->importableFields($table);

        $writer = $format === 'xlsx' && XlsxWriter::isAvailable()
            ? new XlsxWriter
            : new CsvWriter;

        /*
         * The template is the one file the reader fills in by hand, so it gets
         * the same treatment as an export: a real table, headings frozen and
         * columns wide enough to type into.
         */
        if ($writer instanceof XlsxWriter) {
            $writer->describe(
                $table->direction(),
                config('dynamic-table.excel.style', true),
                Str::headline($table->key()).' template',
            );
        }

        $directory = storage_path('app/dynamic-table');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.Str::slug($table->key()).'-template.'.$writer->extension();

        $writer->open($path, Str::headline($table->key()).' template');
        $writer->writeHeadings(array_map(
            static fn (array $field): string => $field['label'],
            array_values($fields),
        ));

        // One hint row explaining the expected content of each column.
        $writer->writeRow(array_map(function (array $field): string {
            if ($field['options'] !== null) {
                return implode(' | ', array_column($field['options'], 'value'));
            }

            return match ($field['type']) {
                'date' => 'YYYY-MM-DD',
                'datetime' => 'YYYY-MM-DD HH:MM:SS',
                'boolean' => 'yes | no',
                'integer', 'decimal' => '0',
                'email' => 'name@example.com',
                default => $field['required'] ? 'required' : '',
            };
        }, array_values($fields)));

        $writer->close();

        return $path;
    }
}

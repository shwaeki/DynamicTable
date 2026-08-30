<?php

namespace Shwaeki\DynamicTable\Modules\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

        return in_array($extension, ['xlsx', 'xls'], true) ? new XlsxReader : new CsvReader;
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
                'required' => ! $column->field->nullable,
                'options' => $column->field->options ?: null,
            ];
        }

        return $fields;
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

        event(new ImportStarted($table->key(), $progressId ?? 'sync', ['mode' => $mode]));

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

            foreach ($rows as [$line, $row]) {
                $errors[] = ['line' => $line, 'errors' => ['_' => [$exception->getMessage()]], 'row' => $row];
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
            $value = is_string($value) ? trim($value) : $value;

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
            return ['status' => 'error', 'errors' => ['_' => [$exception->getMessage()]]];
        }
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

    protected function cast(mixed $value, ColumnDefinition $column): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($column->type) {
            FieldType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            FieldType::Integer => is_numeric($value) ? (int) $value : $value,
            FieldType::Decimal => is_numeric($value) ? (float) $value : $value,
            FieldType::Enum => $this->matchEnum($value, $column),
            default => $value,
        };
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
     * A downloadable CSV listing every rejected row and why it failed.
     *
     * @param  list<array{line: int, errors: array<string, list<string>>, row: list<mixed>}>  $errors
     */
    protected function writeErrorReport(DynamicTable $table, array $errors): string
    {
        $directory = storage_path('app/dynamic-table');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.Str::slug($table->key()).'-import-errors-'.now()->format('Ymd-His').'.csv';

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

        return basename($path);
    }

    /** Build the import template file and return its local path. */
    public function template(DynamicTable $table, string $format = 'csv'): string
    {
        $fields = $this->importableFields($table);

        $writer = $format === 'xlsx' && XlsxWriter::isAvailable()
            ? new XlsxWriter
            : new CsvWriter;

        $directory = storage_path('app/dynamic-table');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.Str::slug($table->key()).'-template.'.$writer->extension();

        $writer->open($path);
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

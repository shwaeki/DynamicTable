<?php

namespace Shwaeki\DynamicTable\Modules\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\Columns\ColumnDefinition;
use Shwaeki\DynamicTable\Contracts\SpreadsheetWriter;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Events\ExportCompleted;
use Shwaeki\DynamicTable\Events\ExportStarted;
use Shwaeki\DynamicTable\Modules\Export\Writers\CsvWriter;
use Shwaeki\DynamicTable\Modules\Export\Writers\XlsxWriter;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Query\RowFormatter;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Support\TransferProgress;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports respect the active view: its columns, order, filters, search and
 * sort. Rows are streamed in chunks, so a million-row export uses the same
 * memory as a ten-row one.
 */
class ExportManager
{
    public function __construct(
        protected QueryEngine $queries,
        protected RowFormatter $formatter,
    ) {}

    public function writerFor(string $format): SpreadsheetWriter
    {
        if ($format === 'xlsx' && XlsxWriter::isAvailable()) {
            return new XlsxWriter;
        }

        return new CsvWriter;
    }

    public function supportedFormats(): array
    {
        return XlsxWriter::isAvailable() ? ['csv', 'xlsx'] : ['csv'];
    }

    /**
     * How many records the requested scope would produce, used to decide
     * between a direct download and a queued job.
     */
    public function estimate(DynamicTable $table, TableState $state, string $scope): int
    {
        if ($scope === 'page') {
            return $state->perPage;
        }

        return $this->query($table, $state, $scope)->toBase()->getCountForPagination();
    }

    /** @return Builder<Model> */
    public function query(DynamicTable $table, TableState $state, string $scope): Builder
    {
        return match ($scope) {
            'selected' => $this->queries->selectionQuery($table, $state),
            'all' => $this->queries->baseQuery($table, $state)
                ->with($this->queries->relationsFor($table, $state)),
            default => $this->queries->build($table, $state),
        };
    }

    /** @return list<ColumnDefinition> */
    public function columns(DynamicTable $table, TableState $state): array
    {
        return array_values(array_filter(
            $this->queries->activeColumns($table, $state),
            static fn (ColumnDefinition $column): bool => $column->exportable,
        ));
    }

    /** A direct, streamed download for small and medium exports. */
    public function stream(DynamicTable $table, TableState $state, string $scope, string $format): StreamedResponse
    {
        $columns = $this->columns($table, $state);
        $writer = $this->writerFor($format);
        $filename = $this->filename($table, $writer->extension());

        if ($writer instanceof XlsxWriter) {
            // XLSX cannot be streamed to the client; build then send.
            $path = $this->write($table, $state, $scope, $format);

            return response()->streamDownload(function () use ($path): void {
                $stream = fopen($path, 'rb');
                fpassthru($stream);
                fclose($stream);
                @unlink($path);
            }, $filename, ['Content-Type' => $writer->contentType()]);
        }

        return response()->streamDownload(function () use ($table, $state, $scope, $columns, $writer): void {
            $handle = fopen('php://output', 'wb');
            $writer->open($handle);
            $writer->writeHeadings(array_map(
                static fn (ColumnDefinition $column): string => $column->label,
                $columns,
            ));

            $this->each($table, $state, $scope, $columns, function (array $row) use ($writer): void {
                $writer->writeRow($row);
            });

            $writer->close();
            fflush($handle);
        }, $filename, [
            'Content-Type' => $writer->contentType(),
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Write the export to the configured disk and return the local path.
     * Used by the queued job and by XLSX downloads.
     */
    public function write(DynamicTable $table, TableState $state, string $scope, string $format, ?string $progressId = null): string
    {
        $columns = $this->columns($table, $state);
        $writer = $this->writerFor($format);
        $path = $this->temporaryPath($writer->extension());

        $writer->open($path);
        $writer->writeHeadings(array_map(
            static fn (ColumnDefinition $column): string => $column->label,
            $columns,
        ));

        $this->each($table, $state, $scope, $columns, function (array $row) use ($writer, $progressId): void {
            $writer->writeRow($row);

            if ($progressId !== null) {
                TransferProgress::advance($progressId);
            }
        });

        $writer->close();

        return $path;
    }

    /** Store a finished export on the configured disk and return its key. */
    public function store(string $localPath, DynamicTable $table, string $extension): string
    {
        $disk = Storage::disk(config('dynamic-table.excel.disk'));
        $key = trim((string) config('dynamic-table.excel.directory', 'dynamic-table'), '/')
            .'/'.$this->filename($table, $extension);

        $stream = fopen($localPath, 'rb');
        $disk->put($key, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        @unlink($localPath);

        return $key;
    }

    /**
     * @param  list<ColumnDefinition>  $columns
     * @param  callable(list<mixed>): void  $callback
     */
    protected function each(
        DynamicTable $table,
        TableState $state,
        string $scope,
        array $columns,
        callable $callback,
    ): void {
        $chunk = max(100, (int) config('dynamic-table.excel.chunk', 1000));
        $query = $this->query($table, $state, $scope);

        event(new ExportStarted($table->key(), 'sync', ['scope' => $scope]));

        if ($scope === 'page') {
            $records = $query->forPage($state->page, $state->perPage)->get();

            foreach ($records as $record) {
                $callback($this->rowValues($record, $columns, $table));
            }

            event(new ExportCompleted($table->key(), 'sync', ['scope' => $scope]));

            return;
        }

        $query->chunk($chunk, function ($records) use ($callback, $columns, $table): void {
            foreach ($records as $record) {
                $callback($this->rowValues($record, $columns, $table));
            }
        });

        event(new ExportCompleted($table->key(), 'sync', ['scope' => $scope]));
    }

    /**
     * The readable text inside a cell that renders markup.
     *
     * A file gets the value, never the presentation. Decorative parts are
     * dropped rather than transcribed — a rating exports as "3.7 / 5", not as
     * four stars followed by "3.7 / 5" — and adjacent elements keep the space
     * between them that the markup implied, so a list of chips does not arrive
     * as one run-together word.
     */
    protected function plainText(string $value): string
    {
        $value = (string) preg_replace('/<([a-z]+)\b[^>]*aria-hidden="true"[^>]*>.*?<\/\1>/is', '', $value);
        $value = str_replace('><', '> <', $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param  list<ColumnDefinition>  $columns
     * @return list<mixed>
     */
    protected function rowValues(mixed $record, array $columns, DynamicTable $table): array
    {
        $row = $this->formatter->row($record, $columns, $table);
        $values = [];

        foreach ($columns as $column) {
            $value = $row['c'][$column->key] ?? null;

            $values[] = match (true) {
                $value === true => (string) __('dynamic-table::table.yes'),
                $value === false => (string) __('dynamic-table::table.no'),
                // A column that renders markup — a progress bar, a render
                // closure returning HTML — would otherwise put tags in the
                // file. The renderers keep their text inside the markup, so
                // stripping it leaves the value a reader wanted.
                ($column->raw || isset($row['h'][$column->key])) && is_string($value) => $this->plainText($value),
                default => $value,
            };
        }

        return $values;
    }

    public function filename(DynamicTable $table, string $extension): string
    {
        return Str::slug($table->key()).'-'.now()->format('Ymd-His').'.'.$extension;
    }

    protected function temporaryPath(string $extension): string
    {
        $directory = storage_path('app/dynamic-table');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory.'/'.Str::lower((string) Str::ulid()).'.'.$extension;
    }
}

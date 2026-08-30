<?php

namespace Shwaeki\DynamicTable\Modules\Export\Writers;

use RuntimeException;
use Shwaeki\DynamicTable\Contracts\SpreadsheetWriter;

/**
 * Dependency-free streaming CSV writer.
 *
 * Writes a UTF-8 BOM so Excel opens non-ASCII (Arabic, Hebrew, Russian) files
 * correctly, and neutralises formula-injection prefixes.
 */
class CsvWriter implements SpreadsheetWriter
{
    /** @var resource|null */
    protected $handle = null;

    protected bool $ownsHandle = false;

    public function __construct(
        protected string $delimiter = ',',
        protected bool $bom = true,
    ) {}

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    public function open(mixed $target, string $sheetName = 'Sheet1'): void
    {
        if (is_resource($target)) {
            $this->handle = $target;
            $this->ownsHandle = false;
        } else {
            $handle = fopen((string) $target, 'wb');

            if ($handle === false) {
                throw new RuntimeException("Unable to open [{$target}] for writing.");
            }

            $this->handle = $handle;
            $this->ownsHandle = true;
        }

        if ($this->bom) {
            fwrite($this->handle, "\xEF\xBB\xBF");
        }
    }

    public function writeHeadings(array $headings): void
    {
        $this->writeRow($headings);
    }

    public function writeRow(array $row): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('CsvWriter::open() must be called first.');
        }

        fputcsv($this->handle, array_map($this->sanitize(...), $row), $this->delimiter, '"', '\\');
    }

    public function close(): void
    {
        if ($this->handle !== null && $this->ownsHandle) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    /** Stop spreadsheet apps from evaluating an exported value as a formula. */
    protected function sanitize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = (string) $value;

        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}

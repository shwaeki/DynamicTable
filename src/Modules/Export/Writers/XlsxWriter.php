<?php

namespace Shwaeki\DynamicTable\Modules\Export\Writers;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Shwaeki\DynamicTable\Contracts\SpreadsheetWriter;

/**
 * XLSX output, delegated to whichever library the host application already has.
 *
 * Preference order is openspout (constant memory, MIT) then PhpSpreadsheet
 * (which Laravel Excel also depends on). Neither is a hard dependency: if
 * neither is installed the caller falls back to CSV.
 */
class XlsxWriter implements SpreadsheetWriter
{
    protected mixed $driver = null;

    protected ?string $path = null;

    protected bool $spout = false;

    public static function isAvailable(): bool
    {
        return class_exists(Writer::class)
            || class_exists(Spreadsheet::class);
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function open(mixed $target, string $sheetName = 'Sheet1'): void
    {
        if (is_resource($target)) {
            throw new RuntimeException('XLSX output requires a file path, not a stream.');
        }

        $this->path = (string) $target;

        if (class_exists(Writer::class)) {
            $this->spout = true;
            $writer = new Writer;
            $writer->openToFile($this->path);
            $this->driver = $writer;

            return;
        }

        if (! class_exists(Spreadsheet::class)) {
            throw new RuntimeException(
                'XLSX export needs openspout/openspout or phpoffice/phpspreadsheet. '.
                'Install one, or export as CSV.'
            );
        }

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle(substr($sheetName, 0, 31));

        $this->driver = ['book' => $spreadsheet, 'row' => 1];
    }

    public function writeHeadings(array $headings): void
    {
        $this->writeRow($headings);
    }

    public function writeRow(array $row): void
    {
        if ($this->driver === null) {
            throw new RuntimeException('XlsxWriter::open() must be called first.');
        }

        if ($this->spout) {
            $this->driver->addRow(Row::fromValues(array_map(
                static fn (mixed $value): mixed => is_bool($value) ? ($value ? 1 : 0) : $value,
                $row,
            )));

            return;
        }

        $sheet = $this->driver['book']->getActiveSheet();
        $sheet->fromArray($row, null, 'A'.$this->driver['row'], true);
        $this->driver['row']++;
    }

    public function close(): void
    {
        if ($this->driver === null) {
            return;
        }

        if ($this->spout) {
            $this->driver->close();
        } else {
            $writer = new Xlsx($this->driver['book']);
            $writer->save((string) $this->path);
            $this->driver['book']->disconnectWorksheets();
        }

        $this->driver = null;
    }
}

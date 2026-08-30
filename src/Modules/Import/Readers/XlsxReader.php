<?php

namespace Shwaeki\DynamicTable\Modules\Import\Readers;

use Generator;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Shwaeki\DynamicTable\Contracts\SpreadsheetReader;

/**
 * XLSX input via openspout (streaming) or PhpSpreadsheet (read-only + chunked
 * cell iteration). Neither is a hard dependency.
 */
class XlsxReader implements SpreadsheetReader
{
    public static function isAvailable(): bool
    {
        return class_exists(Reader::class)
            || class_exists(IOFactory::class);
    }

    public function headings(string $path): array
    {
        foreach ($this->allRows($path) as $row) {
            return array_map(static fn (mixed $value): string => trim((string) $value), $row);
        }

        return [];
    }

    public function rows(string $path): Generator
    {
        $first = true;

        foreach ($this->allRows($path) as $row) {
            if ($first) {
                $first = false;

                continue;
            }

            yield $row;
        }
    }

    public function countRows(string $path): ?int
    {
        $count = -1;

        foreach ($this->allRows($path) as $ignored) {
            $count++;
        }

        return max(0, $count);
    }

    /** @return Generator<int, list<mixed>> */
    protected function allRows(string $path): Generator
    {
        if (class_exists(Reader::class)) {
            $reader = new Reader;
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }

                break;
            }

            $reader->close();

            return;
        }

        if (! class_exists(IOFactory::class)) {
            throw new RuntimeException(
                'XLSX import needs openspout/openspout or phpoffice/phpspreadsheet. '.
                'Install one, or import a CSV file.'
            );
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($sheet->getRowIterator() as $row) {
            $values = [];

            foreach ($row->getCellIterator() as $cell) {
                $values[] = $cell->getValue();
            }

            yield $values;
        }

        $spreadsheet->disconnectWorksheets();
    }
}

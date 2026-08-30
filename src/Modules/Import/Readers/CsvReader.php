<?php

namespace Shwaeki\DynamicTable\Modules\Import\Readers;

use Generator;
use RuntimeException;
use Shwaeki\DynamicTable\Contracts\SpreadsheetReader;

/** Dependency-free streaming CSV reader. */
class CsvReader implements SpreadsheetReader
{
    public function __construct(protected string $delimiter = ',') {}

    public function headings(string $path): array
    {
        $handle = $this->open($path);
        $row = fgetcsv($handle, 0, $this->delimiter, '"', '\\');
        fclose($handle);

        if ($row === false) {
            return [];
        }

        return array_map(
            static fn (mixed $value): string => trim((string) $value),
            $this->stripBom($row),
        );
    }

    public function rows(string $path): Generator
    {
        $handle = $this->open($path);
        $first = true;

        while (($row = fgetcsv($handle, 0, $this->delimiter, '"', '\\')) !== false) {
            if ($first) {
                $first = false;

                continue;
            }

            if ($row === [null]) {
                continue;
            }

            yield $row;
        }

        fclose($handle);
    }

    public function countRows(string $path): ?int
    {
        $handle = $this->open($path);
        $count = -1;

        while (fgetcsv($handle, 0, $this->delimiter, '"', '\\') !== false) {
            $count++;
        }

        fclose($handle);

        return max(0, $count);
    }

    /** @return resource */
    protected function open(string $path)
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read [{$path}].");
        }

        return $handle;
    }

    /**
     * @param  list<mixed>  $row
     * @return list<mixed>
     */
    protected function stripBom(array $row): array
    {
        if (isset($row[0]) && is_string($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        }

        return $row;
    }
}

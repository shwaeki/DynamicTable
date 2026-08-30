<?php

namespace Shwaeki\DynamicTable\Contracts;

use Generator;

interface SpreadsheetReader
{
    /** @return list<string> */
    public function headings(string $path): array;

    /**
     * Yields data rows (excluding the heading row) as lists of scalars.
     *
     * @return Generator<int, list<mixed>>
     */
    public function rows(string $path): Generator;

    public function countRows(string $path): ?int;
}

<?php

namespace Shwaeki\DynamicTable\Contracts;

/**
 * Minimal write contract so the package is not tied to one Excel library.
 *
 * Implementations must stream: open() prepares the target, writeRow() appends,
 * close() finalises. Nothing may accumulate the full data set in memory.
 */
interface SpreadsheetWriter
{
    public function extension(): string;

    public function contentType(): string;

    /** @param resource|string $target A stream resource or a file path. */
    public function open(mixed $target, string $sheetName = 'Sheet1'): void;

    /** @param list<mixed> $row */
    public function writeRow(array $row): void;

    /** @param list<string> $headings */
    public function writeHeadings(array $headings): void;

    public function close(): void;
}

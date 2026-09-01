<?php

namespace Shwaeki\DynamicTable\Modules\Export\Writers;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Shwaeki\DynamicTable\Contracts\SpreadsheetWriter;

/**
 * XLSX output, delegated to whichever library the host application already has.
 *
 * Preference order is openspout (constant memory, MIT) then PhpSpreadsheet
 * (which Laravel Excel also depends on). Neither is a hard dependency: if
 * neither is installed the caller falls back to CSV.
 *
 * The file is written as a filterable range rather than as a grid of values:
 * a headed, frozen first row, a filter across every column, banded rows and
 * columns sized to what is in them. That is what a spreadsheet is for — the
 * first thing anyone does with an exported table is sort or filter it, and
 * doing that on a bare grid means selecting the range by hand first.
 *
 * Colours are literal here rather than taken from the package's CSS tokens: the
 * file is opened in Excel, which has no idea what a custom property is, and it
 * has to be legible on a printer as well as on a screen.
 */
class XlsxWriter implements SpreadsheetWriter
{
    /** Slate, and a tint of it. Dark enough for white text, quiet enough to print. */
    protected const HEADER_FILL = '1F2937';

    protected const HEADER_INK = 'FFFFFF';

    protected const BAND_FILL = 'F3F4F6';

    protected const RULE = 'D1D5DB';

    /** Excel's width unit is roughly one character, so these are character counts. */
    protected const MIN_WIDTH = 9.0;

    protected const MAX_WIDTH = 52.0;

    protected mixed $driver = null;

    protected ?string $path = null;

    protected bool $spout = false;

    protected bool $styled = true;

    protected bool $rtl = false;

    protected int $columns = 0;

    /** Data rows written so far, the heading not counted. */
    protected int $rows = 0;

    /** @var array<int, float> column index (1-based) => widest text seen in it */
    protected array $widths = [];

    /**
     * The Excel table style to register the range under, or null for the
     * hand-painted look. A real table paints its own header and stripes, so the
     * two are alternatives rather than layers.
     */
    protected ?string $tableStyle = null;

    protected string $name = 'Table';

    /** @var list<string> the heading row exactly as written */
    protected array $headings = [];

    protected ?Style $headStyle = null;

    protected ?Style $bandStyle = null;

    public static function isAvailable(): bool
    {
        return class_exists(Writer::class)
            || class_exists(Spreadsheet::class);
    }

    /**
     * Which library writes the file.
     *
     * openspout unless the application says otherwise: its memory is flat
     * whatever the row count, which is the whole reason the exporter streams.
     * Naming the other one in config is for an application that would rather
     * keep a single spreadsheet library, usually because Laravel Excel already
     * brought PhpSpreadsheet in.
     */
    public static function prefersSpout(): bool
    {
        if (! class_exists(Writer::class)) {
            return false;
        }

        return config('dynamic-table.excel.adapter', 'auto') !== 'phpspreadsheet'
            || ! class_exists(Spreadsheet::class);
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    /**
     * What this particular export is, told to the writer before it opens.
     *
     * Kept off the SpreadsheetWriter contract on purpose: a CSV has no reading
     * direction and no styling, and widening the interface for one format would
     * make every other implementation carry a method it cannot use. The manager
     * already knows which writer it is holding.
     */
    public function describe(string $direction = 'ltr', string|bool|null $style = true, string $name = 'Table'): void
    {
        $this->rtl = $direction === 'rtl';
        $this->name = $name;

        if (is_string($style) && $style !== '') {
            if (! XlsxTableParts::isKnownStyle($style)) {
                throw new RuntimeException(
                    "[{$style}] is not an Excel table style. Use one of TableStyleLight1-21, "
                    .'TableStyleMedium1-28 or TableStyleDark1-11, or set dynamic-table.excel.style '
                    .'to true for a styled range and false for a bare grid.'
                );
            }

            $this->tableStyle = $style;
            $this->styled = true;

            return;
        }

        $this->tableStyle = null;
        $this->styled = (bool) $style;
    }

    public function open(mixed $target, string $sheetName = 'Sheet1'): void
    {
        if (is_resource($target)) {
            throw new RuntimeException('XLSX output requires a file path, not a stream.');
        }

        $this->path = (string) $target;
        $this->rows = 0;
        $this->widths = [];

        if (self::prefersSpout()) {
            $this->openSpout($sheetName);

            return;
        }

        if (! class_exists(Spreadsheet::class)) {
            throw new RuntimeException(
                'XLSX export needs openspout/openspout or phpoffice/phpspreadsheet. '.
                'Install one, or export as CSV.'
            );
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetName($sheetName));
        $sheet->setRightToLeft($this->rtl);

        $this->driver = ['book' => $spreadsheet, 'row' => 1];
    }

    public function writeHeadings(array $headings): void
    {
        /*
         * A table's column names have to be unique, non-empty, and identical to
         * the text in the header cells — Excel offers to repair the file when
         * they disagree. So the cells are relabelled too, not just the table.
         */
        if ($this->tableStyle !== null) {
            $headings = XlsxTableParts::uniqueHeadings($headings);
        }

        $this->headings = array_map(static fn (mixed $value): string => (string) $value, $headings);
        $this->columns = count($headings);

        $this->measure($headings);

        if (! $this->spout) {
            $this->writeRow($headings);

            return;
        }

        // A table style paints the header itself; a second fill on top of it
        // would be the reader's, not Excel's, and would not follow the style.
        $style = $this->styled && $this->tableStyle === null ? $this->headStyle() : null;

        $this->driver->addRow(Row::fromValues($headings, $style));
    }

    public function writeRow(array $row): void
    {
        if ($this->driver === null) {
            throw new RuntimeException('XlsxWriter::open() must be called first.');
        }

        $this->columns = max($this->columns, count($row));
        $this->measure($row);

        if ($this->spout) {
            // Banding is what makes a long export readable across the page. The
            // two styles are built once and handed to every row that needs them.
            $style = $this->styled && $this->tableStyle === null && $this->rows % 2 === 1
                ? $this->bandStyle()
                : null;

            $this->driver->addRow(Row::fromValues(array_map(
                static fn (mixed $value): mixed => is_bool($value) ? ($value ? 1 : 0) : $value,
                $row,
            ), $style));

            $this->rows++;

            return;
        }

        $sheet = $this->driver['book']->getActiveSheet();
        $sheet->fromArray($row, null, 'A'.$this->driver['row'], true);
        $this->driver['row']++;

        if ($this->driver['row'] > 2) {
            $this->rows++;
        }
    }

    public function close(): void
    {
        if ($this->driver === null) {
            return;
        }

        if ($this->spout) {
            $this->finishSpout();
            $this->driver->close();

            // Only once the workbook is written and closed is there an archive
            // to add the table part to.
            if ($this->tableStyle !== null) {
                XlsxTableParts::inject(
                    (string) $this->path,
                    $this->name,
                    $this->headings,
                    $this->rows,
                    $this->tableStyle,
                );
            }
        } else {
            $this->finishSpreadsheet();

            $writer = new Xlsx($this->driver['book']);
            $writer->save((string) $this->path);
            $this->driver['book']->disconnectWorksheets();
        }

        $this->driver = null;
    }

    /* ------------------------------------------------------------------ */
    /* openspout */
    /* ------------------------------------------------------------------ */

    protected function openSpout(string $sheetName): void
    {
        $this->spout = true;

        $writer = new Writer;
        $writer->openToFile($this->path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName($this->sheetName($sheetName));

        /*
         * The heading row is frozen whether or not the file is styled: it costs
         * nothing and it is the difference between scrolling a long export and
         * losing track of which column is which.
         */
        $sheet->setSheetView(
            (new SheetView)
                ->setFreezeRow(2)
                ->setRightToLeft($this->rtl),
        );

        $this->driver = $writer;
    }

    /**
     * The filter range and the column widths, both of which openspout writes
     * when the workbook is assembled rather than when a row is added — so they
     * can be decided here, from what actually went into the file, instead of
     * being guessed before the first row.
     */
    protected function finishSpout(): void
    {
        $sheet = $this->driver->getCurrentSheet();

        // A table brings its own filter, added with it after the file is
        // closed; two over one range is what makes Excel offer to repair it.
        if ($this->columns > 0 && $this->tableStyle === null) {
            // openspout counts columns from zero here, and rows from one.
            $sheet->setAutoFilter(new AutoFilter(0, 1, $this->columns - 1, $this->rows + 1));
        }

        foreach ($this->widths as $index => $width) {
            $sheet->setColumnWidth($width, $index);
        }
    }

    protected function headStyle(): Style
    {
        return $this->headStyle ??= (new Style)
            ->setFontBold()
            ->setFontColor(self::HEADER_INK)
            ->setBackgroundColor(self::HEADER_FILL)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setBorder(new Border(
                new BorderPart(Border::BOTTOM, self::RULE, Border::WIDTH_THIN, Border::STYLE_SOLID),
            ));
    }

    protected function bandStyle(): Style
    {
        return $this->bandStyle ??= (new Style)->setBackgroundColor(self::BAND_FILL);
    }

    /* ------------------------------------------------------------------ */
    /* PhpSpreadsheet */
    /* ------------------------------------------------------------------ */

    protected function finishSpreadsheet(): void
    {
        $sheet = $this->driver['book']->getActiveSheet();
        $last = $this->driver['row'] - 1;

        if ($this->columns < 1 || $last < 1) {
            return;
        }

        /*
         * Coordinate::stringFromColumnIndex() and getColumnDimension() are the
         * two spellings that have survived every PhpSpreadsheet major. The
         * ByColumn variants this used to reach for were dropped in 2.0, and
         * composer.json allows ^1.29 through ^4.0.
         */
        $lastColumn = Coordinate::stringFromColumnIndex($this->columns);
        $range = 'A1:'.$lastColumn.$last;

        $sheet->freezePane('A2');

        foreach ($this->widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth($width);
        }

        if ($this->tableStyle !== null) {
            $table = new Table($range, XlsxTableParts::displayName($this->name));
            $table->setStyle((new TableStyle)->setTheme($this->tableStyle)->setShowRowStripes(true));
            $sheet->addTable($table);

            return;
        }

        $sheet->setAutoFilter($range);

        if (! $this->styled) {
            return;
        }

        $head = $sheet->getStyle('A1:'.$lastColumn.'1');
        $head->getFont()->setBold(true)->getColor()->setARGB('FF'.self::HEADER_INK);
        $head->getFill()->setFillType('solid')->getStartColor()->setARGB('FF'.self::HEADER_FILL);

        for ($row = 3; $row <= $last; $row += 2) {
            $sheet->getStyle('A'.$row.':'.$lastColumn.$row)
                ->getFill()->setFillType('solid')->getStartColor()->setARGB('FF'.self::BAND_FILL);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Track how wide each column needs to be, one integer per column rather
     * than a copy of the data: an export of a million rows measures itself for
     * the price of the headings.
     *
     * @param  list<mixed>  $row
     */
    protected function measure(array $row): void
    {
        if (! $this->styled) {
            return;
        }

        foreach (array_values($row) as $index => $value) {
            if ($value === null || is_bool($value)) {
                continue;
            }

            // Padding for the filter arrow the heading now carries, and a
            // little air either side of a value.
            $width = min(self::MAX_WIDTH, max(self::MIN_WIDTH, mb_strlen((string) $value) + 4));

            $this->widths[$index + 1] = max($this->widths[$index + 1] ?? 0, $width);
        }
    }

    /** Excel refuses a sheet name over 31 characters, or one containing []:*?/\. */
    protected function sheetName(string $name): string
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name));

        return mb_substr($name === '' ? 'Sheet1' : $name, 0, 31);
    }
}

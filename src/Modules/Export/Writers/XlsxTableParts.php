<?php

namespace Shwaeki\DynamicTable\Modules\Export\Writers;

use RuntimeException;
use ZipArchive;

/**
 * Turns a written range into a real Excel table — the thing "Format as Table"
 * makes, with the Table Design ribbon, structured references and a name.
 *
 * openspout has no notion of one, and adding it upstream is not on offer, so
 * the four OOXML parts a table needs are added to the finished file: the table
 * itself, a relationship from the sheet to it, the sheet's reference back, and
 * a content-type override. That is the whole of it — a table is a small part
 * that points at cells someone else already wrote.
 *
 * The alternative was to leave a real table to PhpSpreadsheet alone, which
 * would mean the same export producing visibly different files depending on
 * which library the host application happens to have.
 */
final class XlsxTableParts
{
    /**
     * Distinctive on purpose: it shares the sheet's rels file with whatever the
     * writing library put there, and rId1 is the first thing anyone picks.
     */
    private const RELATIONSHIP_ID = 'rIdDynamicTable1';

    /** Excel's own built-ins. A name outside this set makes Excel drop the style silently. */
    private const STYLE_PATTERN = '/^TableStyle(Light([1-9]|1\d|2[01])|Medium([1-9]|1\d|2[0-8])|Dark([1-9]|1[01]))$/';

    public static function isKnownStyle(string $style): bool
    {
        return preg_match(self::STYLE_PATTERN, $style) === 1;
    }

    /**
     * @param  list<string>  $headings  exactly the strings written into row 1
     * @param  int  $rows  data rows, the heading not counted
     */
    public static function inject(string $path, string $name, array $headings, int $rows, string $style): void
    {
        if ($headings === []) {
            return;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to reopen [{$path}] to add the table.");
        }

        $sheet = self::firstSheet($zip);

        if ($sheet === null) {
            $zip->close();

            return;
        }

        $reference = 'A1:'.self::columnLetters(count($headings) - 1).($rows + 1);
        $relsPath = self::relsPath($sheet);
        $existingRels = $zip->getFromName($relsPath);

        $zip->addFromString('xl/tables/table1.xml', self::table($name, $headings, $reference, $style));
        $zip->addFromString($relsPath, self::rels($existingRels === false ? null : $existingRels));
        $zip->addFromString($sheet, self::sheet((string) $zip->getFromName($sheet)));
        $zip->addFromString('[Content_Types].xml', self::contentTypes((string) $zip->getFromName('[Content_Types].xml')));

        $zip->close();
    }

    private static function firstSheet(ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);

            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $entry) === 1) {
                return $entry;
            }
        }

        return null;
    }

    private static function relsPath(string $sheet): string
    {
        return dirname($sheet).'/_rels/'.basename($sheet).'.rels';
    }

    /**
     * The sheet's end of the link.
     *
     * The sheet-level filter goes: a table carries its own, and two filters
     * over one range is the sort of thing that makes Excel offer to repair the
     * file. tableParts is last in the worksheet's element order, so it goes
     * immediately before the closing tag.
     */
    private static function sheet(string $xml): string
    {
        $xml = (string) preg_replace('#<autoFilter[^>]*/>#', '', $xml);

        return str_replace(
            '</worksheet>',
            '<tableParts count="1"><tablePart r:id="'.self::RELATIONSHIP_ID.'"/></tableParts></worksheet>',
            $xml,
        );
    }

    /**
     * The relationship is added to the sheet's existing rels, never written
     * over them.
     *
     * openspout puts a rels file there already — it attaches a comments part
     * and its VML drawing to every sheet — and replacing it left both of those
     * in the archive with nothing pointing at them, which is the shape of file
     * Excel offers to repair. Nothing here may assume it is the only thing that
     * ever wanted to relate something to this sheet.
     */
    private static function rels(?string $existing): string
    {
        $relationship = '<Relationship Id="'.self::RELATIONSHIP_ID.'" '
            .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" '
            .'Target="../tables/table1.xml"/>';

        if ($existing === null || ! str_contains($existing, '</Relationships>')) {
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .$relationship
                .'</Relationships>';
        }

        if (str_contains($existing, self::RELATIONSHIP_ID)) {
            return $existing;
        }

        return str_replace('</Relationships>', $relationship.'</Relationships>', $existing);
    }

    private static function contentTypes(string $xml): string
    {
        if (str_contains($xml, '/xl/tables/table1.xml')) {
            return $xml;
        }

        return str_replace(
            '</Types>',
            '<Override PartName="/xl/tables/table1.xml" '
            .'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/></Types>',
            $xml,
        );
    }

    /** @param list<string> $headings */
    private static function table(string $name, array $headings, string $reference, string $style): string
    {
        $columns = '';

        foreach ($headings as $index => $heading) {
            $columns .= '<tableColumn id="'.($index + 1).'" name="'.self::escape($heading).'"/>';
        }

        $name = self::displayName($name);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'id="1" name="'.$name.'" displayName="'.$name.'" ref="'.$reference.'" '
            .'headerRowCount="1" totalsRowShown="0">'
            .'<autoFilter ref="'.$reference.'"/>'
            .'<tableColumns count="'.count($headings).'">'.$columns.'</tableColumns>'
            .'<tableStyleInfo name="'.self::escape($style).'" showFirstColumn="0" showLastColumn="0" '
            .'showRowStripes="1" showColumnStripes="0"/>'
            .'</table>';
    }

    /**
     * A table's name is an Excel name, not a label: letters, digits, full stops
     * and underscores, never starting with a digit, and never something Excel
     * would read as a cell reference.
     */
    public static function displayName(string $name): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{N}_.]+/u', '_', $name);
        $name = trim($name, '_');

        if ($name === '' || preg_match('/^\d/', $name) === 1) {
            $name = 'Table_'.$name;
        }

        // R1C1 and A1 both name cells, and Excel refuses either as a table name.
        if (preg_match('/^([A-Z]{1,3}\d+|[RC]\d*)$/i', $name) === 1) {
            $name .= '_';
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * Column names have to be unique and non-empty, and they have to match the
     * text in the header cells exactly — Excel repairs the file when they
     * disagree, which is why the writer relabels the cells with these rather
     * than only relabelling the table.
     *
     * @param  list<string>  $headings
     * @return list<string>
     */
    public static function uniqueHeadings(array $headings): array
    {
        $seen = [];
        $out = [];

        foreach ($headings as $index => $heading) {
            $heading = trim((string) $heading);

            if ($heading === '') {
                $heading = 'Column '.($index + 1);
            }

            $key = mb_strtolower($heading);
            $suffix = 1;

            while (isset($seen[$key])) {
                $suffix++;
                $key = mb_strtolower($heading.' '.$suffix);
            }

            $seen[$key] = true;
            $out[] = $suffix === 1 ? $heading : $heading.' '.$suffix;
        }

        return $out;
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    private static function columnLetters(int $index): string
    {
        $letters = '';

        do {
            $letters = chr(65 + $index % 26).$letters;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $letters;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

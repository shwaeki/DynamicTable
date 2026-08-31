<?php

namespace Shwaeki\DynamicTable\Support;

/**
 * Date patterns written the way people write them.
 *
 * PHP's own format characters are a memory test — `d/m/Y`, `jS F y`, and `i`
 * for minutes because `m` was taken. Everyone who has ever configured a report
 * writes `dd/mm/yyyy` instead, so that is accepted too:
 *
 *     'created_at' => ['format' => 'date:dd/mm/yyyy'],
 *     'due_at'     => ['format' => 'datetime:dd/mm/yyyy hh:ii'],
 *
 * Both vocabularies work. A pattern that repeats a letter — `dd`, `yyyy`,
 * `mm` — is read as the friendly one and translated; anything else is handed
 * to PHP unchanged, so every pattern that worked before still does.
 *
 * The tokens, in the Excel/ICU sense rather than the moment.js one:
 *
 *   yyyy 2026   yy 26
 *   mmmm March  mmm Mar   mm 03   m 3
 *   dddd Monday ddd Mon   dd 09   d 9
 *   HH 14  H 14   hh 02  h 2   (24-hour upper, 12-hour lower)
 *   ii 05  ss 09  a pm  A PM
 *
 * `mm` between an hour and a second — `HH:mm:ss` — is minutes, the way a
 * spreadsheet reads it, so a moment.js habit gives the right answer as well.
 * Any other letter is escaped, so it prints as itself rather than as whatever
 * PHP would have made of it.
 */
final class DateFormat
{
    /** Friendly token => PHP format character. */
    private const TOKENS = [
        'yyyy' => 'Y', 'yy' => 'y',
        'mmmm' => 'F', 'mmm' => 'M', 'mm' => 'm', 'm' => 'n',
        'dddd' => 'l', 'ddd' => 'D', 'dd' => 'd', 'd' => 'j',
        'HH' => 'H', 'H' => 'G', 'hh' => 'h', 'h' => 'g',
        'ii' => 'i', 'i' => 'i', 'ss' => 's', 's' => 's',
        'a' => 'a', 'A' => 'A',
    ];

    /** The tokens that mean an hour, so a following "mm" reads as minutes. */
    private const HOURS = ['HH', 'H', 'hh', 'h'];

    /**
     * Is this a pattern in the friendly vocabulary rather than PHP's?
     *
     * A doubled letter is the giveaway: PHP has no two-character codes, so
     * `dd`, `mm`, `yyyy` and `hh` can only have been meant this way.
     */
    public static function isFriendly(string $pattern): bool
    {
        return preg_match('/(yy|mm|dd|hh|ss|ii)/i', $pattern) === 1;
    }

    /** Does this look like a date pattern at all, rather than a format name? */
    public static function looksLikePattern(string $value): bool
    {
        return self::isFriendly($value) || preg_match('/^[dDjlNSwzWFmMntLoXxYyaABgGhHisuveIOPpTZcru\W]+$/', $value) === 1;
    }

    /** The same pattern, in the characters PHP's date() understands. */
    public static function toPhp(string $pattern): string
    {
        if (! self::isFriendly($pattern)) {
            return $pattern;
        }

        $tokens = (array) preg_split(
            "/('[^']*'|yyyy|yy|mmmm|mmm|mm|m|dddd|ddd|dd|d|HH|hh|H|h|ii|i|ss|s|A|a)/",
            $pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        $out = '';

        foreach ($tokens as $index => $token) {
            // 'at', 'de', 'ب' — a word in quotes is printed as it stands.
            if (str_starts_with((string) $token, "'")) {
                $out .= self::literal(trim((string) $token, "'"));

                continue;
            }

            if (! isset(self::TOKENS[$token]) || ! self::isToken($tokens, $index)) {
                $out .= self::literal((string) $token);

                continue;
            }

            $out .= ($token === 'mm' || $token === 'm') && self::isMinute($tokens, $index)
                ? 'i'
                : self::TOKENS[$token];
        }

        return $out;
    }

    /**
     * Is a lone "a" meridiem, or is it the word it appears in?
     *
     * "dd mmm yyyy at HH:ii" is a pattern people write, and the `at` in it is
     * a word. Only an `a` that follows a clock is am/pm.
     *
     * @param  array<int, string>  $tokens
     */
    private static function isToken(array $tokens, int $index): bool
    {
        if ($tokens[$index] !== 'a' && $tokens[$index] !== 'A') {
            return true;
        }

        $before = self::neighbour($tokens, $index, -1);

        return $before !== null && in_array($before, [...self::HOURS, 'ii', 'i'], true);
    }

    /**
     * Is this "m" a minute rather than a month?
     *
     * Only next to a clock: an hour before it, or seconds after it, with
     * nothing but separators in between.
     *
     * @param  array<int, string>  $tokens
     */
    private static function isMinute(array $tokens, int $index): bool
    {
        $before = self::neighbour($tokens, $index, -1);
        $after = self::neighbour($tokens, $index, 1);

        return in_array($before, self::HOURS, true) || $after === 'ss' || $after === 's';
    }

    /**
     * The nearest token in one direction that is not a separator.
     *
     * @param  array<int, string>  $tokens
     */
    private static function neighbour(array $tokens, int $index, int $step): ?string
    {
        for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
            if (isset(self::TOKENS[$tokens[$i]])) {
                return $tokens[$i];
            }

            // A separator is anything that is not a token; letters in between
            // (a word like "at") end the search rather than being skipped.
            if (preg_match('/[a-z]/i', $tokens[$i]) === 1) {
                return null;
            }
        }

        return null;
    }

    /** Text between the tokens, escaped so date() prints it as it stands. */
    private static function literal(string $text): string
    {
        return (string) preg_replace('/([a-z])/i', '\\\\$1', $text);
    }
}

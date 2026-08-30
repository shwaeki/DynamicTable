<?php

namespace Shwaeki\DynamicTable\Columns;

use Illuminate\Support\Carbon;

/**
 * The built-in cell renderers.
 *
 * A progress bar, a rating, a sparkline: the handful of presentations people
 * write the same render closure for on every project. They are formats rather
 * than a new concept —
 *
 *     'score' => ['format' => 'progress'],
 *
 * — so they compose with everything a column already has, and the column is
 * marked raw automatically because the output is markup.
 *
 * Two rules hold throughout:
 *
 * 1. **No dependencies and no client work.** Everything here is inline SVG or
 *    a span with a CSS class, rendered on the server, so it survives a page
 *    with JavaScript disabled and needs no chart library.
 * 2. **The text is inside the markup.** A progress bar contains "62%", a
 *    rating contains "4/5". Exports strip the tags, so a cell that reads
 *    usefully on screen still reads usefully in a CSV.
 */
class CellRenderers
{
    /** Every format this class handles, so the resolver knows to mark them raw. */
    public const FORMATS = ['progress', 'rating', 'sparkline', 'chips', 'avatar', 'duration'];

    /**
     * A bar with the value written on it.
     *
     * The argument is the value that counts as full — `progress:2000` over a
     * sales target, `progress` alone for a percentage.
     */
    public static function progress(mixed $value, ?string $argument = null): string
    {
        $max = is_numeric($argument) ? (float) $argument : 100.0;
        $number = (float) $value;
        $ratio = $max > 0 ? max(0.0, min(1.0, $number / $max)) : 0.0;
        $percent = round($ratio * 100);

        // The width is the only inline style, because it is data rather than
        // design; the colours come from the tokens.
        return '<span class="dt-progress" role="img" aria-label="'.e($percent.'%').'">'
            .'<span class="dt-progress-track"><span class="dt-progress-bar" style="inline-size:'.$percent.'%"></span></span>'
            .'<span class="dt-progress-value">'.e(self::trim($number).($max === 100.0 ? '%' : ' / '.self::trim($max))).'</span>'
            .'</span>';
    }

    /** Filled and empty stars, with the number kept for anyone who cannot see them. */
    public static function rating(mixed $value, ?string $argument = null): string
    {
        $out = is_numeric($argument) ? (int) $argument : 5;
        $score = max(0.0, min((float) $out, (float) $value));
        $filled = (int) round($score);

        $stars = str_repeat('★', $filled).str_repeat('☆', max(0, $out - $filled));

        return '<span class="dt-rating" title="'.e(self::trim($score).' / '.$out).'">'
            .'<span class="dt-rating-stars" aria-hidden="true">'.$stars.'</span>'
            .'<span class="dt-visually-hidden">'.e(self::trim($score).' / '.$out).'</span>'
            .'</span>';
    }

    /**
     * A trend line for an array of numbers.
     *
     * Drawn as an SVG polyline with a viewBox, so it scales with the row height
     * and costs nothing to render — a chart library for a 60-pixel line would
     * be an absurd trade.
     */
    public static function sparkline(mixed $value, ?string $argument = null): ?string
    {
        $points = self::numbers($value);

        if (count($points) < 2) {
            return null;
        }

        $low = min($points);
        $high = max($points);
        $span = $high - $low ?: 1.0;
        $step = 100 / (count($points) - 1);

        $coordinates = [];

        foreach ($points as $index => $point) {
            // SVG's y grows downwards, so the value is inverted.
            $coordinates[] = round($index * $step, 2).','.round(20 - (($point - $low) / $span) * 20, 2);
        }

        $last = end($points);

        return '<span class="dt-sparkline">'
            .'<svg viewBox="0 0 100 20" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            .'<polyline points="'.e(implode(' ', $coordinates)).'" />'
            .'</svg>'
            .'<span class="dt-sparkline-value">'.e(self::trim((float) $last)).'</span>'
            .'</span>';
    }

    /** A list of small pills, for an array of tags, roles or labels. */
    public static function chips(mixed $value, ?string $argument = null): ?string
    {
        $items = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)));

        if ($items === []) {
            return null;
        }

        $limit = is_numeric($argument) ? (int) $argument : 4;
        $shown = array_slice($items, 0, $limit);
        $rest = count($items) - count($shown);

        $html = '<span class="dt-chips">';

        foreach ($shown as $item) {
            $html .= '<span class="dt-chip">'.e(is_scalar($item) ? (string) $item : json_encode($item)).'</span>';
        }

        if ($rest > 0) {
            $html .= '<span class="dt-chip dt-chip-more">+'.$rest.'</span>';
        }

        return $html.'</span>';
    }

    /** A round thumbnail. The argument is the alt text, when the value is a bare URL. */
    public static function avatar(mixed $value, ?string $argument = null): ?string
    {
        $url = (string) $value;

        if ($url === '') {
            return null;
        }

        return '<img src="'.e($url).'" alt="'.e((string) $argument).'" class="dt-avatar" loading="lazy" decoding="async">';
    }

    /** Seconds as "1h 20m", or "45s" when that is all there is. */
    public static function duration(mixed $value, ?string $argument = null): string
    {
        $seconds = (int) round((float) $value);
        $interval = Carbon::now()->diff(Carbon::now()->addSeconds($seconds));

        $parts = array_filter([
            $interval->days ? $interval->days.'d' : null,
            $interval->h ? $interval->h.'h' : null,
            $interval->i ? $interval->i.'m' : null,
            $interval->days || $interval->h ? null : ($interval->s ? $interval->s.'s' : null),
        ]);

        return '<span class="dt-plain">'.e($parts === [] ? '0s' : implode(' ', $parts)).'</span>';
    }

    /** @return list<float> */
    private static function numbers(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): float => (float) $item,
            array_filter($value, static fn (mixed $item): bool => is_numeric($item)),
        ));
    }

    /** Whole numbers without a pointless ".00". */
    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}

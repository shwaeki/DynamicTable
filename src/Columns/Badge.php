<?php

namespace Shwaeki\DynamicTable\Columns;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The `badges` column option: a value drawn as a coloured pill, not as text.
 *
 * Every project writes the same render closure for this — a span, a class per
 * state, the label inside — so it is a column option instead:
 *
 *     'status' => ['badges' => ['paid' => 'success', 'overdue' => 'danger']],
 *     'active' => ['badges' => [1 => ['success', 'Active'], 0 => ['danger', 'Inactive']]],
 *     'state'  => ['badges' => true],
 *     'status' => ['badges' => fn ($value, $record) => [$record->status_color, $record->status_name]],
 *
 * The label stays inside the markup, so an export of a badge column still
 * reads as the word it shows.
 */
final class Badge
{
    /** The tones the package stylesheet paints. Anything else is yours to style. */
    public const TONES = ['success', 'danger', 'warning', 'info', 'primary', 'neutral'];

    /** Words that mean a state, so `'badges' => true` can colour one unaided. */
    private const KEYWORDS = [
        'success' => ['active', 'enabled', 'approved', 'paid', 'completed', 'complete', 'done', 'published', 'delivered', 'shipped', 'open', 'yes', 'true', 'success', 'ok'],
        'danger' => ['inactive', 'disabled', 'rejected', 'failed', 'error', 'cancelled', 'canceled', 'overdue', 'blocked', 'banned', 'closed', 'no', 'false', 'danger'],
        'warning' => ['pending', 'draft', 'waiting', 'processing', 'review', 'on_hold', 'hold', 'partial', 'warning', 'unpaid'],
        'info' => ['new', 'scheduled', 'queued', 'info', 'sent'],
    ];

    /** One badge, as ready-to-insert markup. */
    public static function html(string $label, ?string $tone, string $badgeClass = 'dt-badge'): string
    {
        return '<span class="'.e(self::classes($badgeClass, $tone)).'">'.e($label).'</span>';
    }

    /**
     * The class list for one badge.
     *
     * A theme that writes `{tone}` in its badge slot decides for itself where
     * the tone goes — `'badge badge-light-{tone}'` for an admin template whose
     * CSS is already written. Every other theme gets the package's own
     * modifier appended, which its stylesheet paints.
     */
    public static function classes(string $badgeClass, ?string $tone): string
    {
        $tone = self::normaliseTone($tone);

        if (str_contains($badgeClass, '{tone}')) {
            return trim(str_replace('{tone}', $tone ?? 'neutral', $badgeClass));
        }

        return $tone === null ? $badgeClass : trim($badgeClass.' dt-badge-'.$tone);
    }

    /**
     * What this cell's badge should say and be coloured.
     *
     * @param  array<array-key, mixed>|Closure|bool  $spec  the column's badges option
     * @param  mixed  $value  the value behind the cell, before display formatting
     * @param  string  $label  the cell as it would otherwise read
     * @return array{0: string, 1: ?string}|null [label, tone], or null for no badge
     */
    public static function resolve(array|Closure|bool $spec, mixed $value, string $label, Model $record): ?array
    {
        if ($spec === false || $spec === []) {
            return null;
        }

        if ($spec instanceof Closure) {
            return self::normalise($spec($value, $record), $label);
        }

        if ($spec === true) {
            return [$label, self::tone($value, $label)];
        }

        $key = self::key($value);
        $entry = $spec[$key] ?? $spec[$label] ?? $spec['*'] ?? null;

        return $entry === null
            ? [$label, self::tone($value, $label)]
            : self::normalise($entry, $label);
    }

    /**
     * Read one entry of the map, or whatever a closure returned.
     *
     * A tone on its own, `[tone, label]`, or `['tone' => …, 'label' => …]` —
     * `color` is accepted for `tone`, because that is what the model accessor
     * people already have is usually called.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function normalise(mixed $entry, string $label): ?array
    {
        if ($entry === null || $entry === false) {
            return null;
        }

        if ($entry === true) {
            return [$label, null];
        }

        if (is_string($entry)) {
            return [$label, $entry];
        }

        if ($entry instanceof UnitEnum) {
            return [$label, self::key($entry)];
        }

        if (is_array($entry)) {
            $tone = $entry['tone'] ?? $entry['color'] ?? $entry[0] ?? null;
            $text = $entry['label'] ?? $entry['text'] ?? $entry[1] ?? $label;

            return [(string) $text, $tone === null ? null : (string) $tone];
        }

        return [$label, null];
    }

    /** The tone a value suggests on its own, for `'badges' => true`. */
    public static function tone(mixed $value, string $label = ''): string
    {
        $needle = strtolower(trim(self::key($value) !== '' ? self::key($value) : $label));

        foreach (self::KEYWORDS as $tone => $words) {
            if (in_array($needle, $words, true)) {
                return $tone;
            }
        }

        return 'neutral';
    }

    /** The map key a value is looked up under. */
    private static function key(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** Keep a tone to something that is safe as a class suffix. */
    private static function normaliseTone(?string $tone): ?string
    {
        if ($tone === null || trim($tone) === '') {
            return null;
        }

        $tone = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($tone)), '-');

        return $tone === '' ? null : strtolower($tone);
    }
}

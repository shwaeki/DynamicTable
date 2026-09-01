<?php

namespace Shwaeki\DynamicTable\Query;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\Columns\Badge;
use Shwaeki\DynamicTable\Columns\CellRenderers;
use Shwaeki\DynamicTable\Columns\ColumnDefinition;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Metadata\FieldType;
use Shwaeki\DynamicTable\Support\DateFormat;
use Shwaeki\DynamicTable\Support\Theme;
use UnitEnum;

/**
 * Converts hydrated models into the compact row payload sent to the browser.
 *
 * Display strings are produced here rather than in JavaScript so that dates,
 * numbers and currency follow the application locale, and so that a custom
 * render closure runs on the server where it has the full model.
 */
class RowFormatter
{
    /** @var array<string, string> The badge classes of each theme met so far. */
    private array $badgeClasses = [];

    /**
     * @param  iterable<Model>  $records
     * @param  list<ColumnDefinition>  $columns
     * @return list<array<string, mixed>>
     */
    public function rows(iterable $records, array $columns, DynamicTable $table): array
    {
        $rows = [];

        foreach ($records as $record) {
            $rows[] = $this->row($record, $columns, $table);
        }

        return $rows;
    }

    /**
     * @param  list<ColumnDefinition>  $columns
     * @return array<string, mixed>
     */
    public function row(Model $record, array $columns, DynamicTable $table): array
    {
        $cells = [];
        $raw = [];
        $html = [];

        foreach ($columns as $column) {
            $value = $this->extract($record, $column);

            if ($column->render !== null) {
                $rendered = ($column->render)($value, $record, $column);

                // Returning an Htmlable (e.g. a Blade view, or new HtmlString)
                // is an explicit statement that the value is already safe HTML,
                // so it does not also need the raw flag. The cell is marked as
                // markup for this row alone, so a closure that returns plain
                // text for some records still has that text escaped.
                if ($rendered instanceof Htmlable) {
                    $cells[$column->key] = $rendered->toHtml();
                    $html[$column->key] = true;
                } else {
                    $cells[$column->key] = (string) $rendered;
                }
            } else {
                $cells[$column->key] = $this->badge($this->display($value, $column), $value, $column, $record, $table);
            }

            $cells[$column->key] = $this->placeholder($cells[$column->key], $column);

            if ($column->editable) {
                $raw[$column->key] = $this->rawValue($value);
            }
        }

        $row = [
            'id' => $record->getKey(),
            'c' => $cells,
        ];

        if ($raw !== []) {
            $row['r'] = $raw;
        }

        if ($html !== []) {
            $row['h'] = $html;
        }

        // Keyed off the model, not a feature: a table that reaches trashed
        // rows through its own query() still wants them struck through.
        if ($table->metadata()->usesSoftDeletes && method_exists($record, 'trashed') && $record->trashed()) {
            $row['trashed'] = true;
        }

        // Row actions are decided per record: visibility and authorisation can
        // both depend on the row, so the browser is told exactly which buttons
        // this row gets — and the server checks again before running one.
        $actions = [];

        foreach ($table->availableRowActions() as $action) {
            if (! $action->appliesTo($table, $record)) {
                continue;
            }

            $actions[$action->name] = $action->isLink() ? $action->forRecord($record) : true;
        }

        if ($actions !== []) {
            $row['a'] = $actions;
        }

        return $row;
    }

    /**
     * A cell drawn as a badge, when the column asked for that.
     *
     * The label goes inside the markup rather than beside it, so the exporter
     * strips the tags and still writes the word the reader saw.
     */
    protected function badge(string|bool|null $display, mixed $value, ColumnDefinition $column, Model $record, DynamicTable $table): string|bool|null
    {
        if ($column->badges === [] || $column->badges === false || $display === null || $display === '') {
            return $display;
        }

        $label = is_bool($display)
            ? (string) __('dynamic-table::table.'.($display ? 'yes' : 'no'))
            : $display;

        $badge = Badge::resolve($column->badges, $value, $label, $record);

        return $badge === null ? $display : Badge::html($badge[0], $badge[1], $this->badgeClass($table));
    }

    /** What a column says instead of nothing, when it was given the words. */
    protected function placeholder(string|bool|null $cell, ColumnDefinition $column): string|bool|null
    {
        return ($cell === null || $cell === '') && $column->placeholder !== null
            ? $column->placeholder
            : $cell;
    }

    protected function badgeClass(DynamicTable $table): string
    {
        $theme = $table->theme();

        return $this->badgeClasses[$theme] ??= (string) (Theme::classes($theme)['badge'] ?? 'dynamic-table-badge');
    }

    protected function extract(Model $record, ColumnDefinition $column): mixed
    {
        $path = $column->field->path;

        if (! str_contains($path, '.')) {
            return $record->getAttribute($path);
        }

        $segments = explode('.', $path);
        $attribute = array_pop($segments);
        $current = $record;

        foreach ($segments as $segment) {
            if (! $current instanceof Model) {
                return null;
            }

            // Only read relations that were eager loaded: touching an unloaded
            // relation here is exactly the N+1 this package exists to avoid.
            if (! $current->relationLoaded($segment)) {
                return null;
            }

            $current = $current->getRelation($segment);

            if ($current instanceof Collection) {
                $current = $current->first();
            }

            if ($current === null) {
                return null;
            }
        }

        return $current instanceof Model ? $current->getAttribute($attribute) : null;
    }

    /**
     * A column's aggregate, formatted the way that column's values are.
     *
     * A total under a currency column has to read as currency, or the reader
     * has to do the conversion in their head. A count is the exception: it
     * counts rows, not money, so it is only ever a plain number.
     *
     * @param  array<string, float|int|null>  $summaries
     * @param  list<ColumnDefinition>  $columns
     * @return array<string, string>
     */
    public function summaries(array $summaries, array $columns): array
    {
        $formatted = [];

        foreach ($columns as $column) {
            if (! array_key_exists($column->key, $summaries)) {
                continue;
            }

            $value = $summaries[$column->key];

            if ($value === null) {
                $formatted[$column->key] = '—';

                continue;
            }

            $formatted[$column->key] = $column->summary === 'count'
                ? $this->number((float) $value, 0)
                : (string) $this->display($value, $column);
        }

        return $formatted;
    }

    protected function display(mixed $value, ColumnDefinition $column): string|bool|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UnitEnum) {
            $raw = $value instanceof BackedEnum ? $value->value : $value->name;

            foreach ($column->field->options as $option) {
                if ((string) $option['value'] === (string) $raw) {
                    return $option['label'];
                }
            }

            return Str::headline((string) $raw);
        }

        if ($column->format !== null) {
            $formatted = $this->applyFormat($value, $column->format, $column);

            if ($formatted !== null) {
                return $formatted;
            }
        }

        return match ($column->type) {
            FieldType::Boolean => (bool) $value,
            FieldType::Date => $this->date($value, 'date'),
            FieldType::DateTime => $this->date($value, 'datetime'),
            FieldType::Time => $this->date($value, 'time'),
            FieldType::Json => $this->json($value),
            FieldType::Decimal => $this->number((float) $value),
            FieldType::Integer => (string) $value,
            default => $this->stringify($value),
        };
    }

    protected function applyFormat(mixed $value, string $format, ColumnDefinition $column): ?string
    {
        [$name, $argument] = array_pad(explode(':', $format, 2), 2, null);

        return match ($name) {
            'currency' => $this->currency((float) $value, $argument),
            'number' => $this->number((float) $value, $argument !== null ? (int) $argument : null),
            'percent' => $this->number((float) $value, $argument !== null ? (int) $argument : 1).'%',
            'bytes' => $this->bytes((int) $value),
            'date' => $this->date($value, 'date', $argument),
            'datetime' => $this->date($value, 'datetime', $argument),
            'time' => $this->date($value, 'time', $argument),
            'since' => $value instanceof DateTimeInterface ? Carbon::instance($value)->diffForHumans() : null,
            'upper' => Str::upper((string) $value),
            'lower' => Str::lower((string) $value),
            'headline' => Str::headline((string) $value),
            'truncate' => Str::limit((string) $value, $argument !== null ? (int) $argument : 60),

            // The built-in cell renderers. They return markup, which is why the
            // resolver marks a column using one of them as raw.
            'progress' => CellRenderers::progress($value, $argument),
            'rating' => CellRenderers::rating($value, $argument),
            'sparkline' => CellRenderers::sparkline($value, $argument),
            'chips' => CellRenderers::chips($value, $argument),
            'avatar' => CellRenderers::avatar($value, $argument),
            'duration' => CellRenderers::duration($value, $argument),

            // A date column may name its pattern on its own — 'dd/mm/yyyy' —
            // without the date: prefix, because that is what everyone writes.
            default => $this->pattern($value, $format, $column),
        };
    }

    /**
     * A format that is not a name but a date pattern, on a column that can use
     * one. Anything else is left alone, so a typo stays visible as a typo.
     */
    protected function pattern(mixed $value, string $format, ColumnDefinition $column): ?string
    {
        $kind = match ($column->type) {
            FieldType::Date => 'date',
            FieldType::Time => 'time',
            FieldType::DateTime => 'datetime',
            default => null,
        };

        if ($kind === null && ! $value instanceof DateTimeInterface) {
            return null;
        }

        return DateFormat::looksLikePattern($format)
            ? $this->date($value, $kind ?? 'datetime', $format)
            : null;
    }

    protected function date(mixed $value, string $kind, ?string $pattern = null): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            try {
                $value = Carbon::parse((string) $value);
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return Carbon::instance($value)->translatedFormat(
            DateFormat::toPhp($pattern ?? $this->defaultPattern($kind)),
        );
    }

    /** @see DateFormat::defaultPattern(), which the importer reads as well. */
    protected function defaultPattern(string $kind): string
    {
        return DateFormat::defaultPattern($kind);
    }

    protected function number(float $value, ?int $decimals = null): string
    {
        $decimals ??= floor($value) === $value ? 0 : 2;

        return number_format($value, $decimals, '.', ',');
    }

    protected function currency(float $value, ?string $currency): string
    {
        $currency = strtoupper($currency ?? 'USD');

        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'ILS' => '₪', 'JOD' => 'JD', 'AED' => 'AED', 'SAR' => 'SAR'];
        $symbol = $symbols[$currency] ?? $currency.' ';

        return $symbol.$this->number($value, 2);
    }

    protected function bytes(int $value): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $value > 0 ? (int) floor(log($value, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return $this->number($value / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }

    protected function json(mixed $value): string
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_string($value)) {
            return Str::limit($value, 200);
        }

        return Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200);
    }

    protected function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || $value instanceof Arrayable) {
            return $this->json($value);
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateTimeString();
        }

        return (string) $value;
    }

    protected function rawValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        return $value;
    }
}

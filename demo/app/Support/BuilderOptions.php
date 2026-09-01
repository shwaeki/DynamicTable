<?php

namespace App\Support;

use Illuminate\Support\Facades\Session;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * What the builder page is currently showing, and the code that would produce
 * it.
 *
 * The point of the page is that these are the same thing: every control maps
 * onto one property of a DynamicTable, the preview is that table, and the code
 * panel is that table written out. Nothing here describes the package — it
 * configures it.
 */
class BuilderOptions
{
    public const SESSION_KEY = 'demo.builder';

    /**
     * Features offered in the panel, grouped the way the documentation groups
     * them. Defaults are marked so the UI can say what is on for free.
     *
     * @return array<string, list<string>>
     */
    public static function featureGroups(): array
    {
        return [
            'Basics' => [Feature::SEARCH, Feature::FILTERS, Feature::SORTING, Feature::PAGINATION, Feature::RESPONSIVE, Feature::HEADER_MENU],
            'Columns' => [Feature::COLUMN_PICKER, Feature::COLUMN_REORDER, Feature::COLUMN_RESIZE, Feature::COLUMN_SEARCH, Feature::STICKY_COLUMNS, Feature::GROUPING],
            'Selecting & acting' => [Feature::SELECTION, Feature::BULK_ACTIONS, Feature::BULK_EDIT, Feature::ROW_ACTIONS, Feature::TOOLBAR_ACTIONS],
            'Writing' => [Feature::INLINE_EDIT, Feature::INLINE_CREATE, Feature::IMPORT, Feature::EXPORT, Feature::PRINT],
            'More' => [Feature::SAVED_VIEWS, Feature::ROW_DETAIL, Feature::FILTER_COUNTS, Feature::RELATIONS, Feature::URL_STATE, Feature::REMEMBER_STATE],
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'features' => Feature::DEFAULTS,
            'theme' => 'demo',
            'panels' => 'modal',
            'responsive' => 'collapse',
            'pagination' => 'auto',
            'perPage' => 10,
            'maxHeight' => '60vh',
            'direction' => 'auto',
            'scheme' => 'auto',
            'sticky' => false,
            'summary' => true,
        ];
    }

    /** @return array<string, mixed> */
    public static function current(): array
    {
        return array_merge(self::defaults(), (array) Session::get(self::SESSION_KEY, []));
    }

    /**
     * @param  array<string, mixed>  $input  untrusted
     * @return array<string, mixed>
     */
    public static function store(array $input): array
    {
        $allowed = array_merge(...array_values(self::featureGroups()));

        $features = array_values(array_intersect(
            array_map('strval', (array) ($input['features'] ?? [])),
            $allowed,
        ));

        // Freezing columns is a feature, not only a property: ticking the box
        // without it would print code that does nothing.
        if (($input['sticky'] ?? false) && ! in_array(Feature::STICKY_COLUMNS, $features, true)) {
            $features[] = Feature::STICKY_COLUMNS;
        }

        $options = [
            'features' => $features,
            'theme' => self::pick($input['theme'] ?? null, ['demo', 'tailwind', 'bootstrap', 'minimal', 'bordered'], 'demo'),
            'panels' => self::pick($input['panels'] ?? null, ['modal', 'offcanvas'], 'modal'),
            'responsive' => self::pick($input['responsive'] ?? null, ['collapse', 'cards', 'scroll', 'none'], 'collapse'),
            'pagination' => self::pick($input['pagination'] ?? null, ['auto', 'length_aware', 'simple', 'infinite'], 'auto'),
            'perPage' => (int) self::pick((string) ($input['perPage'] ?? ''), ['5', '10', '25', '50'], '10'),
            'maxHeight' => self::pick($input['maxHeight'] ?? null, ['60vh', '80vh', 'none'], '60vh'),
            'direction' => self::pick($input['direction'] ?? null, ['auto', 'ltr', 'rtl'], 'auto'),
            'scheme' => self::pick($input['scheme'] ?? null, ['auto', 'light', 'dark'], 'auto'),
            'sticky' => (bool) ($input['sticky'] ?? false),
            'summary' => (bool) ($input['summary'] ?? false),
        ];

        Session::put(self::SESSION_KEY, $options);

        return $options;
    }

    /**
     * The selection written the way a table declares features.
     *
     * This matters more than it looks: a plain list is *additive to the
     * defaults*, so passing ['filters'] does not switch search off — it leaves
     * it on. Unticking a default therefore has to be expressed as '-search'.
     * The preview and the generated code both go through here, so what is on
     * screen and what is printed cannot disagree.
     *
     * @param  list<string>  $features
     * @return list<string>
     */
    public static function declaration(array $features): array
    {
        $declared = array_values(array_diff($features, Feature::DEFAULTS));

        foreach (Feature::DEFAULTS as $default) {
            if (! in_array($default, $features, true)) {
                $declared[] = '-'.$default;
            }
        }

        return $declared;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function pick(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * The table class that would produce the current selection.
     *
     * Written the way a developer would write it: only what differs from the
     * defaults appears, because the whole argument of the package is that the
     * default is usually right.
     *
     * @param  array<string, mixed>  $options
     */
    public static function code(array $options): string
    {
        // Compared against the *package's* defaults, not the builder's, so the
        // code reproduces what is on screen rather than what this page happens
        // to start with.
        $packageDefaults = [
            'theme' => (string) config('dynamic-table.theme', 'tailwind'),
            'panels' => (string) config('dynamic-table.panels.mode', 'modal'),
            'responsive' => (string) config('dynamic-table.responsive.mode', 'collapse'),
            'pagination' => 'auto',
            'maxHeight' => (string) config('dynamic-table.table.max_height', '70vh'),
            'perPage' => (int) config('dynamic-table.pagination.default', 25),
        ];

        $lines = [];

        $features = $options['features'];
        sort($features);
        $standard = Feature::DEFAULTS;
        sort($standard);

        if ($features !== $standard) {
            $lines[] = self::arrayProperty('array $features', self::declaration($options['features']));
        }

        foreach ([
            'theme' => '?string $theme',
            'panels' => '?string $panels',
            'responsive' => '?string $responsive',
            'pagination' => 'string $pagination',
            'maxHeight' => '?string $maxHeight',
        ] as $key => $declaration) {
            if ((string) $options[$key] !== (string) $packageDefaults[$key]) {
                $lines[] = "    protected {$declaration} = '{$options[$key]}';";
            }
        }

        if ((int) $options['perPage'] !== $packageDefaults['perPage']) {
            $lines[] = "    protected ?int \$perPage = {$options['perPage']};";
        }

        foreach (['direction' => '?string $direction', 'scheme' => '?string $scheme'] as $key => $declaration) {
            if ($options[$key] !== 'auto') {
                $lines[] = "    protected {$declaration} = '{$options[$key]}';";
            }
        }

        if ($options['summary']) {
            $lines[] = "    // in columns(): 'total' => ['format' => 'currency:USD', 'summary' => 'sum'],";
        }

        if ($options['sticky']) {
            $lines[] = "    protected array \$stickyColumns = ['reference'];";
            $lines[] = '    protected bool $stickyActions = true;';
        }

        $body = $lines === [] ? '' : "\n".implode("\n\n", $lines)."\n";

        return <<<PHP
        <?php

        namespace App\DynamicTables;

        use App\Models\Order;
        use Shwaeki\DynamicTable\DynamicTable;

        class OrdersTable extends DynamicTable
        {
            protected string \$model = Order::class;
        {$body}}
        PHP;
    }

    /** @param list<string> $values */
    private static function arrayProperty(string $declaration, array $values): string
    {
        if ($values === []) {
            return "    protected {$declaration} = [];";
        }

        $items = implode("\n", array_map(static fn (string $value): string => "        '{$value}',", $values));

        return "    protected {$declaration} = [\n{$items}\n    ];";
    }
}

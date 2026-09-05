<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Exceptions\DynamicTableException;

/**
 * The places a table lets an application put its own markup.
 *
 * Until this existed, the only way to add a control to the toolbar or a banner
 * above the table was to copy table.blade.php and change it — which works once
 * and then makes every upgrade a merge. A named slot is the supported way to do
 * the same thing and stay on the template.
 *
 * The list is short on purpose. Each name is somewhere the package promises not
 * to draw anything itself, so an application's markup and the table's own can
 * never fight over the same box.
 */
final class Slots
{
    /** Beside the search box, before the table's own start-aligned controls. */
    public const TOOLBAR_START = 'toolbar.start';

    /** After the toolbar's own buttons — export, print, columns, views. */
    public const TOOLBAR_END = 'toolbar.end';

    /** Between the toolbar and the table. */
    public const ABOVE = 'above';

    /** Under the pagination footer. */
    public const BELOW = 'below';

    /** Inside the empty state, under the message: somewhere to put "Add the first one". */
    public const EMPTY_STATE = 'empty';

    /** @var list<string> */
    public const ALL = [
        self::TOOLBAR_START,
        self::TOOLBAR_END,
        self::ABOVE,
        self::BELOW,
        self::EMPTY_STATE,
    ];

    /**
     * Slots the JavaScript renderer draws, and which therefore have to travel
     * in the payload.
     *
     * Everything else is rendered once by Blade into a part of the page the
     * core module never rebuilds, so its markup stays out of the JSON — where
     * it would only be weight on every request.
     *
     * @var list<string>
     */
    public const REPAINTED = [self::EMPTY_STATE];

    /**
     * Every slot the table fills, rendered to HTML and keyed by slot name.
     *
     * @return array<string, string>
     */
    public static function resolve(DynamicTable $table): array
    {
        $declared = $table->slots();

        if (! is_array($declared) || $declared === []) {
            return [];
        }

        $unknown = array_diff(array_keys($declared), self::ALL);

        if ($unknown !== []) {
            // Same reasoning as an unknown feature name: silently dropping it
            // leaves the author looking at a page missing the thing they wrote,
            // with nothing anywhere saying why.
            throw DynamicTableException::unknownSlots(array_values($unknown), self::ALL, $table::class);
        }

        $rendered = [];

        foreach ($declared as $name => $content) {
            $html = self::html($content);

            if ($html !== '') {
                $rendered[$name] = $html;
            }
        }

        return $rendered;
    }

    /**
     * Slot markup the JavaScript renderer needs, ready for the boot payload.
     *
     * @param  array<string, string>  $slots
     * @return array<string, string>
     */
    public static function repainted(array $slots): array
    {
        return array_intersect_key($slots, array_flip(self::REPAINTED));
    }

    /**
     * One slot's value as HTML.
     *
     * The same three shapes `rowDetail()` accepts, and for the same reason: a
     * view and an Htmlable are markup their author meant as markup, while a
     * bare string is text and is escaped. Anything else — a model, an array —
     * is a mistake worth ignoring rather than printing.
     */
    protected static function html(mixed $content): string
    {
        return match (true) {
            $content === null, $content === false => '',
            $content instanceof View => $content->render(),
            $content instanceof Htmlable => $content->toHtml(),
            is_string($content) => e($content),
            is_scalar($content) => e((string) $content),
            default => '',
        };
    }
}

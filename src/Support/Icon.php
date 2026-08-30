<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Support\HtmlString;

/**
 * Icons declared on an action.
 *
 * An icon is whatever the developer wrote in their table class — an emoji, a
 * character, or the markup of an icon font: `<i class="far fa-edit"></i>`.
 * Markup is rendered as markup, anything else is escaped, so a plain string
 * can never turn into a tag. This is developer-authored code, never user
 * input; nothing here should ever be handed a value that came off a request.
 */
final class Icon
{
    /** Does this icon look like markup rather than a glyph? */
    public static function isMarkup(?string $icon): bool
    {
        return $icon !== null && preg_match('/<[a-z!\/]/i', $icon) === 1;
    }

    /** The icon, ready to echo with {!! !!}. */
    public static function html(?string $icon): HtmlString
    {
        $icon = (string) $icon;

        return new HtmlString(self::isMarkup($icon) ? $icon : e($icon));
    }
}

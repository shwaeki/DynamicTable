<?php

namespace Shwaeki\DynamicTable\Support;

/**
 * A theme is a map of slot names to CSS classes — nothing more.
 *
 * Three ship, and only three:
 *
 *   custom      the package's own look; needs nothing on the page
 *   bootstrap   Bootstrap 5's components
 *   tailwind    Tailwind utilities
 *
 * The short list is the feature. A longer one asked every author to work out
 * which of six names their application wanted, and the answer was almost
 * always "the framework I already load, or none". Anything a project wants to
 * change is a slot override under "themes" in config/dynamic-table.php.
 *
 * Because both the Blade template and the JavaScript renderer read the same
 * map, a complete visual theme is one array, and neither Bootstrap nor
 * Tailwind is ever loaded for an application that does not use it.
 */
class Theme
{
    /**
     * Every theme the package ships.
     *
     * @var list<string>
     */
    public const ALL = ['custom', 'bootstrap', 'tailwind'];

    /**
     * Where an unrecognised name lands.
     *
     * "custom" rather than the configured default on purpose: it is the one
     * theme that needs no framework on the page, so a name the package does
     * not know still renders a table that looks finished.
     */
    public const FALLBACK = 'custom';

    /**
     * Every theme name that resolves to something: the three built-ins, plus
     * anything the application defines under "themes" in its config.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_unique([
            ...static::ALL,
            ...array_keys((array) config('dynamic-table.themes', [])),
        ]));
    }

    /**
     * The class map for a theme: structural classes, the built-in map, then
     * whatever the application overrides.
     *
     * A theme named in config starts from the built-in of the same name, so
     * changing one slot means naming one slot:
     *
     *     'themes' => ['bootstrap' => ['badge' => 'badge badge-light-{tone}']],
     *
     * A name of its own starts from "custom", which is already a complete
     * theme — so a project's own theme is as long as the list of slots it
     * actually wants to change, and no longer.
     *
     * @return array<string, string>
     */
    public static function classes(string $name): array
    {
        $overrides = config('dynamic-table.themes.'.$name);
        $overrides = is_array($overrides) ? $overrides : [];

        // "extends" was how a theme named its parent, before every theme
        // started from one. A config left over from then keeps working; the
        // key is not a slot, so it is dropped rather than rendered.
        unset($overrides['extends']);

        return array_merge(
            static::base(),
            match ($name) {
                'bootstrap' => static::bootstrap(),
                'tailwind' => static::tailwind(),
                default => static::custom(),
            },
            $overrides,
        );
    }

    /**
     * Structural classes owned by the package's own stylesheet. Every theme
     * keeps these; they carry behaviour (sticky header, resize handles, RTL)
     * rather than looks.
     *
     * @return array<string, string>
     */
    protected static function base(): array
    {
        return [
            'root' => 'dynamic-table',
            'toolbar' => 'dynamic-table-toolbar',
            'toolbarStart' => 'dynamic-table-toolbar-start',
            'toolbarEnd' => 'dynamic-table-toolbar-end',
            'scroller' => 'dynamic-table-scroller',
            'table' => 'dynamic-table-table',
            'thead' => 'dynamic-table-thead',
            'headRow' => 'dynamic-table-head-row',
            'th' => 'dynamic-table-th',
            'thSortable' => 'dynamic-table-th-sortable',
            'resizer' => 'dynamic-table-resizer',
            'filterRow' => 'dynamic-table-filter-row',
            'tbody' => 'dynamic-table-tbody',
            'row' => 'dynamic-table-row',
            'rowSelected' => 'dynamic-table-row-selected',
            'cell' => 'dynamic-table-cell',
            'cellEditing' => 'dynamic-table-cell-editing',
            'cellInvalid' => 'dynamic-table-cell-invalid',
            'footer' => 'dynamic-table-footer',
            'pagination' => 'dynamic-table-pagination',
            'empty' => 'dynamic-table-empty',
            'loading' => 'dynamic-table-loading',
            'panel' => 'dynamic-table-panel',
            'menu' => 'dynamic-table-menu',
            'menuItem' => 'dynamic-table-menu-item',
            'modal' => 'dynamic-table-modal',
            'modalBox' => 'dynamic-table-modal-box',
            'chip' => 'dynamic-table-chip',
            'group' => 'dynamic-table-group',
        ];
    }

    /**
     * The package's own theme: a card, quiet headers, comfortable rows.
     *
     * Every class it names is painted by the package stylesheet from the same
     * tokens as everything else, so it is readable in light and dark, obeys
     * data-dynamic-table-scheme, and needs neither Bootstrap nor Tailwind nor
     * a build step on the page.
     *
     * The menu slots carry a class of their own rather than leaving the look
     * to a descendant selector: an open menu is portalled to <body>, where
     * .dynamic-table-custom is no longer an ancestor.
     *
     * @return array<string, string>
     */
    protected static function custom(): array
    {
        return [
            'root' => 'dynamic-table dynamic-table-custom',
            'button' => 'dynamic-table-button',
            'buttonPrimary' => 'dynamic-table-button dynamic-table-button-primary',
            'buttonDanger' => 'dynamic-table-button dynamic-table-button-danger',
            'input' => 'dynamic-table-input',
            'search' => 'dynamic-table-input dynamic-table-search',
            'select' => 'dynamic-table-select',
            'badge' => 'dynamic-table-badge',
            'menu' => 'dynamic-table-menu dynamic-table-custom-menu',
            'menuItem' => 'dynamic-table-menu-item dynamic-table-custom-menu-item',
        ];
    }

    /**
     * Bootstrap 5, on the same principle: its components for structure, the
     * package's tokens for colour.
     *
     * Bootstrap's own table colours are CSS variables, which the stylesheet
     * maps to the package tokens — so a table is readable whether or not the
     * host page sets data-bs-theme.
     *
     * @return array<string, string>
     */
    protected static function bootstrap(): array
    {
        return [
            'root' => 'dynamic-table dynamic-table-bootstrap',
            'toolbar' => 'dynamic-table-toolbar d-flex flex-wrap align-items-center gap-2 p-2 border-bottom',
            'search' => 'form-control form-control-sm',
            'button' => 'btn btn-sm btn-outline-secondary',
            'buttonPrimary' => 'btn btn-sm btn-primary',
            'buttonDanger' => 'btn btn-sm btn-danger',
            'input' => 'form-control form-control-sm',
            'select' => 'form-select form-select-sm',
            'wrapper' => 'card',
            'scroller' => 'dynamic-table-scroller table-responsive',
            'table' => 'dynamic-table-table table table-hover align-middle mb-0',
            'thead' => 'dynamic-table-thead',
            'th' => 'dynamic-table-th text-nowrap',
            'row' => 'dynamic-table-row',
            'rowSelected' => 'dynamic-table-row-selected',
            'cell' => 'dynamic-table-cell',
            'footer' => 'dynamic-table-footer d-flex flex-wrap align-items-center justify-content-between gap-2 border-top p-2',
            'empty' => 'dynamic-table-empty text-center py-5',
            'badge' => 'dynamic-table-badge badge',
            'menu' => 'dynamic-table-menu dropdown-menu show shadow',
            'menuItem' => 'dynamic-table-menu-item dropdown-item',
            'modalBox' => 'dynamic-table-modal-box card shadow-lg',
            'chip' => 'dynamic-table-chip badge',
        ];
    }

    /**
     * Tailwind contributes layout, spacing, radii and focus rings.
     *
     * It deliberately contributes no surface, text or border *colour*: those
     * come from the package's own tokens, so the table is readable in light and
     * dark whether or not the host application supports dark mode, and so an
     * explicit data-dynamic-table-scheme is obeyed. Tailwind's `dark:` variants
     * could not do that — under the default media strategy they follow the
     * operating system and cannot be overridden per element.
     *
     * @return array<string, string>
     */
    protected static function tailwind(): array
    {
        return [
            'root' => 'dynamic-table dynamic-table-tailwind text-sm',
            'toolbar' => 'dynamic-table-toolbar flex flex-wrap items-center gap-2 p-3 border-b',
            'search' => 'w-56 rounded-md border px-3 py-1.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/40',
            'button' => 'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm font-medium disabled:opacity-50 hover:opacity-90',
            'buttonPrimary' => 'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium disabled:opacity-50 dynamic-table-btn-primary',
            'buttonDanger' => 'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium dynamic-table-btn-danger',
            'input' => 'w-full rounded-md border px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/40',
            'select' => 'rounded-md border px-2 py-1 text-sm',
            'wrapper' => 'overflow-hidden rounded-lg border shadow-sm',
            'scroller' => 'dynamic-table-scroller overflow-x-auto',
            'table' => 'dynamic-table-table w-full border-collapse text-sm',
            'thead' => 'dynamic-table-thead',
            'th' => 'dynamic-table-th whitespace-nowrap px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide',
            'row' => 'dynamic-table-row',
            'rowSelected' => 'dynamic-table-row-selected',
            'cell' => 'dynamic-table-cell px-3 py-2 align-middle',
            'footer' => 'dynamic-table-footer flex flex-wrap items-center justify-between gap-2 border-t px-3 py-2 text-sm',
            'empty' => 'dynamic-table-empty px-3 py-12 text-center',
            'badge' => 'dynamic-table-badge inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
            'menu' => 'dynamic-table-menu absolute z-40 mt-1 min-w-[14rem] rounded-md border p-1 shadow-lg',
            'menuItem' => 'dynamic-table-menu-item flex w-full items-center gap-2 rounded px-2 py-1.5 text-start',
            'modalBox' => 'dynamic-table-modal-box w-full max-w-2xl rounded-lg p-4 shadow-xl',
            'chip' => 'dynamic-table-chip inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs',
        ];
    }
}

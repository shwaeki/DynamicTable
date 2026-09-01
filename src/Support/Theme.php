<?php

namespace Shwaeki\DynamicTable\Support;

/**
 * A theme is a map of slot names to CSS classes — nothing more.
 *
 * Because both the Blade template and the JavaScript renderer read the same
 * map, a complete visual theme is one array, and neither Bootstrap nor
 * Tailwind is ever loaded for an application that does not use it.
 */
class Theme
{
    /** @var array<string, array<string, string>> */
    protected static array $registered = [];

    /** @param array<string, string> $classes */
    public static function register(string $name, array $classes): void
    {
        static::$registered[$name] = $classes;
    }

    /**
     * Every theme name that resolves to something: the built-ins, whatever the
     * config defines, and anything registered in code.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_unique([
            'tailwind',
            'bootstrap',
            'minimal',
            'bordered',
            ...array_keys((array) config('dynamic-table.themes', [])),
            // A themes file published by an older version, still honoured.
            ...array_keys((array) config('dynamic-table-themes', [])),
            ...array_keys(static::$registered),
        ]));
    }

    /**
     * @param  list<string>  $seen  themes already being resolved, so an
     *                              "extends" cycle cannot recurse forever
     * @return array<string, string>
     */
    public static function classes(string $name, array $seen = []): array
    {
        $base = static::base();

        if (isset(static::$registered[$name])) {
            return static::inherit(array_merge($base, static::$registered[$name]), $name, $seen);
        }

        // config/dynamic-table.php is the home for a project's own themes:
        // publish it and edit the "themes" key there, rather than registering
        // a map from a service provider. A config/dynamic-table-themes.php
        // published by an older version still works, so an upgrade does not
        // silently drop a theme, but the one config file wins.
        $fromConfig = config('dynamic-table.themes.'.$name) ?? config('dynamic-table-themes.'.$name);

        if (is_array($fromConfig)) {
            return static::inherit(array_merge($base, $fromConfig), $name, $seen);
        }

        return match ($name) {
            'bootstrap', 'bootstrap5' => array_merge($base, static::bootstrap()),
            'minimal' => array_merge($base, static::minimal()),
            'bordered' => array_merge($base, static::bordered()),
            'none', 'custom' => $base,
            default => array_merge($base, static::tailwind()),
        };
    }

    /**
     * A theme built on another one.
     *
     *     'metronic' => ['extends' => 'bootstrap', 'badge' => 'badge badge-light-{tone}'],
     *
     * Without this, changing one slot means copying every slot of the theme you
     * were otherwise happy with — and re-copying it whenever the package
     * changes one.
     *
     * @param  array<string, string>  $classes
     * @param  list<string>  $seen
     * @return array<string, string>
     */
    protected static function inherit(array $classes, string $name, array $seen): array
    {
        $parent = $classes['extends'] ?? null;
        unset($classes['extends']);

        if (! is_string($parent) || $parent === '' || in_array($name, $seen, true)) {
            return $classes;
        }

        return array_merge(static::classes($parent, [...$seen, $name]), $classes);
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
     * Ready to use, with no CSS framework at all.
     *
     * Quiet and airy: no outer border, generous rows, rules only between them.
     * Everything it names is styled by the package's own stylesheet, so this
     * theme works on a bare Blade page with nothing else installed.
     *
     * @return array<string, string>
     */
    protected static function minimal(): array
    {
        return [
            'root' => 'dynamic-table dynamic-table-minimal',
            'button' => 'dynamic-table-button',
            'buttonPrimary' => 'dynamic-table-button dynamic-table-button-primary',
            'buttonDanger' => 'dynamic-table-button dynamic-table-button-danger',
            'input' => 'dynamic-table-input',
            'search' => 'dynamic-table-input dynamic-table-search',
            'select' => 'dynamic-table-select',
            'badge' => 'dynamic-table-badge',
        ];
    }

    /**
     * The same framework-free base, ruled like a spreadsheet.
     *
     * Dense rows and a border on every cell: the right default for wide numeric
     * grids, where following a value across twenty columns matters more than
     * white space.
     *
     * @return array<string, string>
     */
    protected static function bordered(): array
    {
        return array_merge(static::minimal(), [
            'root' => 'dynamic-table dynamic-table-minimal dynamic-table-bordered',
        ]);
    }
}

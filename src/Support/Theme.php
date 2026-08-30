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

    /** @return array<string, string> */
    public static function classes(string $name): array
    {
        $base = static::base();

        if (isset(static::$registered[$name])) {
            return array_merge($base, static::$registered[$name]);
        }

        $fromConfig = config('dynamic-table.themes.'.$name);

        if (is_array($fromConfig)) {
            return array_merge($base, $fromConfig);
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
     * Structural classes owned by the package's own stylesheet. Every theme
     * keeps these; they carry behaviour (sticky header, resize handles, RTL)
     * rather than looks.
     *
     * @return array<string, string>
     */
    protected static function base(): array
    {
        return [
            'root' => 'dt',
            'toolbar' => 'dt-toolbar',
            'toolbarStart' => 'dt-toolbar-start',
            'toolbarEnd' => 'dt-toolbar-end',
            'scroller' => 'dt-scroller',
            'table' => 'dt-table',
            'thead' => 'dt-thead',
            'headRow' => 'dt-head-row',
            'th' => 'dt-th',
            'thSortable' => 'dt-th-sortable',
            'resizer' => 'dt-resizer',
            'filterRow' => 'dt-filter-row',
            'tbody' => 'dt-tbody',
            'row' => 'dt-row',
            'rowSelected' => 'dt-row-selected',
            'cell' => 'dt-cell',
            'cellEditing' => 'dt-cell-editing',
            'cellInvalid' => 'dt-cell-invalid',
            'footer' => 'dt-footer',
            'pagination' => 'dt-pagination',
            'empty' => 'dt-empty',
            'loading' => 'dt-loading',
            'panel' => 'dt-panel',
            'menu' => 'dt-menu',
            'menuItem' => 'dt-menu-item',
            'modal' => 'dt-modal',
            'modalBox' => 'dt-modal-box',
            'chip' => 'dt-chip',
            'group' => 'dt-group',
        ];
    }

    /**
     * Tailwind contributes layout, spacing, radii and focus rings.
     *
     * It deliberately contributes no surface, text or border *colour*: those
     * come from the package's own tokens, so the table is readable in light and
     * dark whether or not the host application supports dark mode, and so an
     * explicit data-dt-scheme is obeyed. Tailwind's `dark:` variants could not
     * do that — under the default media strategy they follow the operating
     * system and cannot be overridden per element.
     *
     * @return array<string, string>
     */
    protected static function tailwind(): array
    {
        return [
            'root' => 'dt dt-tailwind text-sm',
            'toolbar' => 'dt-toolbar flex flex-wrap items-center gap-2 p-3 border-b',
            'search' => 'w-56 rounded-md border px-3 py-1.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/40',
            'button' => 'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm font-medium disabled:opacity-50 hover:opacity-90',
            'buttonPrimary' => 'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium disabled:opacity-50 dt-btn-primary',
            'buttonDanger' => 'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium dt-btn-danger',
            'input' => 'w-full rounded-md border px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/40',
            'select' => 'rounded-md border px-2 py-1 text-sm',
            'wrapper' => 'overflow-hidden rounded-lg border shadow-sm',
            'scroller' => 'dt-scroller overflow-x-auto',
            'table' => 'dt-table w-full border-collapse text-sm',
            'thead' => 'dt-thead',
            'th' => 'dt-th whitespace-nowrap px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide',
            'row' => 'dt-row',
            'rowSelected' => 'dt-row-selected',
            'cell' => 'dt-cell px-3 py-2 align-middle',
            'footer' => 'dt-footer flex flex-wrap items-center justify-between gap-2 border-t px-3 py-2 text-sm',
            'empty' => 'dt-empty px-3 py-12 text-center',
            'badge' => 'dt-badge inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
            'menu' => 'dt-menu absolute z-40 mt-1 min-w-[14rem] rounded-md border p-1 shadow-lg',
            'menuItem' => 'dt-menu-item flex w-full items-center gap-2 rounded px-2 py-1.5 text-start',
            'modalBox' => 'dt-modal-box w-full max-w-2xl rounded-lg p-4 shadow-xl',
            'chip' => 'dt-chip inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs',
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
            'root' => 'dt dt-bootstrap',
            'toolbar' => 'dt-toolbar d-flex flex-wrap align-items-center gap-2 p-2 border-bottom',
            'search' => 'form-control form-control-sm',
            'button' => 'btn btn-sm btn-outline-secondary',
            'buttonPrimary' => 'btn btn-sm btn-primary',
            'buttonDanger' => 'btn btn-sm btn-danger',
            'input' => 'form-control form-control-sm',
            'select' => 'form-select form-select-sm',
            'wrapper' => 'card',
            'scroller' => 'dt-scroller table-responsive',
            'table' => 'dt-table table table-hover align-middle mb-0',
            'thead' => 'dt-thead',
            'th' => 'dt-th text-nowrap',
            'row' => 'dt-row',
            'rowSelected' => 'dt-row-selected',
            'cell' => 'dt-cell',
            'footer' => 'dt-footer d-flex flex-wrap align-items-center justify-content-between gap-2 border-top p-2',
            'empty' => 'dt-empty text-center py-5',
            'badge' => 'dt-badge badge',
            'menu' => 'dt-menu dropdown-menu show shadow',
            'menuItem' => 'dt-menu-item dropdown-item',
            'modalBox' => 'dt-modal-box card shadow-lg',
            'chip' => 'dt-chip badge',
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
            'root' => 'dt dt-minimal',
            'button' => 'dt-button',
            'buttonPrimary' => 'dt-button dt-button-primary',
            'buttonDanger' => 'dt-button dt-button-danger',
            'input' => 'dt-input',
            'search' => 'dt-input dt-search',
            'select' => 'dt-select',
            'badge' => 'dt-badge',
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
            'root' => 'dt dt-minimal dt-bordered',
        ]);
    }
}

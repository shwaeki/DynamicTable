<?php

namespace Shwaeki\DynamicTable\Support;

/**
 * The complete feature vocabulary.
 *
 * Cheap features are enabled by default; anything that costs an extra query,
 * an extra JS module or extra client state must be opted into explicitly.
 */
final class Feature
{
    public const SEARCH = 'search';

    public const COLUMN_SEARCH = 'column_search';

    public const FILTERS = 'filters';

    public const SORTING = 'sorting';

    public const PAGINATION = 'pagination';

    public const RESPONSIVE = 'responsive';

    public const VIEWS = 'views';

    public const HEADER_MENU = 'header_menu';

    public const COLUMN_PICKER = 'column_picker';

    public const COLUMN_REORDERING = 'column_reordering';

    public const COLUMN_RESIZING = 'column_resizing';

    public const SELECTION = 'selection';

    public const BULK_ACTIONS = 'bulk_actions';

    public const ROW_ACTIONS = 'row_actions';

    public const TOOLBAR_ACTIONS = 'toolbar_actions';

    public const BULK_EDIT = 'bulk_edit';

    public const CREATE = 'create';

    public const ROW_DETAIL = 'row_detail';

    public const STICKY_COLUMNS = 'sticky_columns';

    public const FACETS = 'facets';

    public const INLINE_EDIT = 'inline_edit';

    public const GROUPING = 'grouping';

    public const EXPORT = 'export';

    public const IMPORT = 'import';

    public const SPREADSHEET = 'spreadsheet';

    public const SOFT_DELETES = 'soft_deletes';

    public const URL_STATE = 'url_state';

    /** Features that are on unless explicitly disabled with a "-" prefix. */
    public const DEFAULTS = [
        self::SEARCH,
        self::FILTERS,
        self::SORTING,
        self::PAGINATION,
        self::RESPONSIVE,
        self::HEADER_MENU,
    ];

    public const ALL = [
        self::SEARCH,
        self::COLUMN_SEARCH,
        self::FILTERS,
        self::SORTING,
        self::PAGINATION,
        self::RESPONSIVE,
        self::HEADER_MENU,
        self::VIEWS,
        self::COLUMN_PICKER,
        self::COLUMN_REORDERING,
        self::COLUMN_RESIZING,
        self::SELECTION,
        self::BULK_ACTIONS,
        self::ROW_ACTIONS,
        self::TOOLBAR_ACTIONS,
        self::BULK_EDIT,
        self::CREATE,
        self::ROW_DETAIL,
        self::STICKY_COLUMNS,
        self::FACETS,
        self::INLINE_EDIT,
        self::GROUPING,
        self::EXPORT,
        self::IMPORT,
        self::SPREADSHEET,
        self::SOFT_DELETES,
        self::URL_STATE,
    ];

    /**
     * Features that imply other features.
     *
     * @var array<string, list<string>>
     */
    public const IMPLIES = [
        self::BULK_ACTIONS => [self::SELECTION],
        self::BULK_EDIT => [self::SELECTION],
        self::CREATE => [self::INLINE_EDIT],
        self::SPREADSHEET => [self::INLINE_EDIT, self::SELECTION],
        self::COLUMN_REORDERING => [self::COLUMN_PICKER],
        self::COLUMN_RESIZING => [self::COLUMN_PICKER],
        self::VIEWS => [self::COLUMN_PICKER],
    ];

    /**
     * Extra JavaScript modules a feature needs, lazily imported by the core.
     *
     * @var array<string, list<string>>
     */
    public const MODULES = [
        self::FILTERS => ['filters'],
        self::VIEWS => ['views'],
        self::COLUMN_PICKER => ['columns'],
        self::COLUMN_REORDERING => ['columns'],
        self::COLUMN_RESIZING => ['columns'],
        self::INLINE_EDIT => ['inline-edit'],
        self::BULK_ACTIONS => ['actions'],
        self::SELECTION => ['actions'],
        self::ROW_ACTIONS => ['actions'],
        self::TOOLBAR_ACTIONS => ['actions'],
        self::BULK_EDIT => ['actions'],
        self::CREATE => ['inline-edit'],
        self::ROW_DETAIL => ['detail'],
        self::STICKY_COLUMNS => ['sticky'],
        self::EXPORT => ['transfer'],
        self::IMPORT => ['transfer'],
        self::SPREADSHEET => ['spreadsheet'],
    ];

    /** Normalise "bulk-actions", "bulkActions" and "BULK_ACTIONS" to "bulk_actions". */
    public static function normalize(string $feature): string
    {
        $feature = str_replace('-', '_', trim($feature));
        $feature = (string) preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $feature);

        return strtolower($feature);
    }
}

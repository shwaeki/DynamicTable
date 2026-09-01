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

    public const SAVED_VIEWS = 'saved_views';

    public const HEADER_MENU = 'header_menu';

    public const COLUMN_PICKER = 'column_picker';

    public const COLUMN_REORDER = 'column_reorder';

    public const COLUMN_RESIZE = 'column_resize';

    public const SELECTION = 'selection';

    public const BULK_ACTIONS = 'bulk_actions';

    public const ROW_ACTIONS = 'row_actions';

    public const TOOLBAR_ACTIONS = 'toolbar_actions';

    public const BULK_EDIT = 'bulk_edit';

    public const INLINE_CREATE = 'inline_create';

    public const ROW_DETAIL = 'row_detail';

    public const STICKY_COLUMNS = 'sticky_columns';

    public const FILTER_COUNTS = 'filter_counts';

    /**
     * Whether a reader may reach through relationships.
     *
     * On (the default) the filter builder and the column picker offer the
     * fields of the model's singular relations as well as its own. Off, they
     * offer only the model's own fields, and a filter on a relation path is
     * refused — the same effect as $relationDepth = 0, but sayable in the
     * feature list and switchable per table.
     *
     * A relation column the table itself declares is unaffected: that is the
     * developer's choice, not the reader's.
     */
    public const RELATIONS = 'relations';

    public const INLINE_EDIT = 'inline_edit';

    public const GROUPING = 'grouping';

    public const PRINT = 'print';

    public const EXPORT = 'export';

    public const IMPORT = 'import';

    public const REMEMBER_STATE = 'remember_state';

    public const URL_STATE = 'url_state';

    /** Features that are on unless explicitly disabled with a "-" prefix. */
    public const DEFAULTS = [
        self::SEARCH,
        self::FILTERS,
        self::SORTING,
        self::PAGINATION,
        self::RESPONSIVE,
        self::HEADER_MENU,
        self::RELATIONS,
    ];

    public const ALL = [
        self::SEARCH,
        self::COLUMN_SEARCH,
        self::FILTERS,
        self::SORTING,
        self::PAGINATION,
        self::RESPONSIVE,
        self::HEADER_MENU,
        self::SAVED_VIEWS,
        self::COLUMN_PICKER,
        self::COLUMN_REORDER,
        self::COLUMN_RESIZE,
        self::SELECTION,
        self::BULK_ACTIONS,
        self::ROW_ACTIONS,
        self::TOOLBAR_ACTIONS,
        self::BULK_EDIT,
        self::INLINE_CREATE,
        self::ROW_DETAIL,
        self::STICKY_COLUMNS,
        self::FILTER_COUNTS,
        self::RELATIONS,
        self::INLINE_EDIT,
        self::GROUPING,
        self::PRINT,
        self::EXPORT,
        self::IMPORT,
        self::REMEMBER_STATE,
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
        self::INLINE_CREATE => [self::INLINE_EDIT],
        // saved_views is the only one that still implies the picker, and
        // it is a data dependency rather than a convenience: a view stores a
        // column selection, so something has to be allowed to restore it.
        //
        // column_reorder and column_resize deliberately imply nothing. They
        // share the picker's panel, but sharing a panel is not a reason to
        // hand the reader Add column and Remove as well.
        self::SAVED_VIEWS => [self::COLUMN_PICKER],
    ];

    /**
     * Extra JavaScript modules a feature needs, lazily imported by the core.
     *
     * @var array<string, list<string>>
     */
    public const MODULES = [
        self::FILTERS => ['filters'],
        self::SAVED_VIEWS => ['views'],
        self::COLUMN_PICKER => ['columns'],
        self::COLUMN_REORDER => ['columns'],
        self::COLUMN_RESIZE => ['columns'],
        self::INLINE_EDIT => ['inline-edit'],
        self::BULK_ACTIONS => ['actions'],
        self::SELECTION => ['actions'],
        self::ROW_ACTIONS => ['actions'],
        self::TOOLBAR_ACTIONS => ['actions'],
        self::BULK_EDIT => ['actions'],
        self::INLINE_CREATE => ['inline-edit'],
        self::ROW_DETAIL => ['detail'],
        self::STICKY_COLUMNS => ['sticky'],
        self::EXPORT => ['transfer'],
        self::IMPORT => ['transfer'],
    ];

    /** Normalise "bulk-actions", "bulkActions" and "BULK_ACTIONS" to "bulk_actions". */
    public static function normalize(string $feature): string
    {
        $feature = str_replace('-', '_', trim($feature));
        $feature = (string) preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $feature);

        return strtolower($feature);
    }
}

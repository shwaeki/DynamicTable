<?php

namespace Shwaeki\DynamicTable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\Actions\BulkAction;
use Shwaeki\DynamicTable\Actions\RowAction;
use Shwaeki\DynamicTable\Actions\ToolbarAction;
use Shwaeki\DynamicTable\Columns\ColumnDefinition;
use Shwaeki\DynamicTable\Columns\ColumnResolver;
use Shwaeki\DynamicTable\Exceptions\DynamicTableException;
use Shwaeki\DynamicTable\Filters\ParamFilters;
use Shwaeki\DynamicTable\Metadata\MetadataEngine;
use Shwaeki\DynamicTable\Metadata\ModelMetadata;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\FeatureSet;
use Shwaeki\DynamicTable\Support\TableEstimator;

/**
 * The one class a developer extends.
 *
 *     class UsersTable extends DynamicTable
 *     {
 *         protected $model = User::class;
 *     }
 *
 * Everything else is either a sensible default or an optional override.
 *
 * The overridable properties are deliberately not declared in this class. PHP
 * requires a redeclared property to repeat its parent's type exactly, so a
 * declaration here would force every table in every application to spell them
 * the same way. With nothing declared, both spellings are yours to pick:
 *
 *     protected $model = User::class;
 *     protected string $model = User::class;
 *
 * and a property you never mention keeps the default documented below. The
 * package only reads them, always through the accessors further down.
 *
 * The methods you override are declared the same way — no return types — for
 * the same reason: PHP will not let a child method drop a type its parent
 * declared, so declaring one here would decide the question for you. Write
 * whichever you prefer:
 *
 *     protected function columns() {}
 *     protected function columns(): array {}
 *
 * Every signature is documented with @return, so static analysis and IDE
 * completion are unaffected.
 *
 * @property class-string<Model>|null $model
 * @property string|null $tableKey
 * @property string|null $title
 * @property list<string> $features
 * @property array<int|string, mixed> $columns
 * @property list<string> $searchable
 * @property list<string> $hiddenColumns
 * @property list<string> $allowedColumns
 * @property array<string, string> $labels
 * @property array<string, string> $defaultSort
 * @property list<string> $scopes
 * @property array<int|string, mixed> $params
 * @property array<int|string, mixed> $paramFilters
 * @property list<string> $with
 * @property string $pagination
 * @property int|null $perPage
 * @property list<int> $perPageOptions
 * @property string|null $maxHeight
 * @property string|null $printView
 * @property list<string> $printStylesheets
 * @property string|null $theme
 * @property string|null $direction
 * @property string|null $scheme
 * @property string|null $panels
 * @property string|null $responsive
 * @property list<string> $responsiveFixed
 * @property list<string> $stickyColumns
 * @property bool $stickyActions
 * @property list<string> $filterCounts
 * @property int $relationDepth
 * @property string|null $policy
 */
abstract class DynamicTable
{
    /*
     * ------------------------------------------------------------------
     * Public API — the properties you declare in your own table class
     * ------------------------------------------------------------------
     *
     * $model (required)
     *     The Eloquent model this table lists.
     *
     * $tableKey = null
     *     Stable identifier used by saved views and the data endpoint.
     *     Defaults to the class name without its "Table" suffix, snake_cased.
     *
     * $title = null
     *     Human title shown above the table. Defaults to the model's plural
     *     name.
     *
     * $features = []
     *     Opt-in features. Prefix with "-" to switch a default off.
     *
     * $columns = []
     *     Explicit column list. Empty means "discover them". The columns()
     *     method says the same thing when the list needs code to build it.
     *
     * $searchable = []
     *     Fields the global search box looks at. Empty means "pick safe ones".
     *
     * $hiddenColumns = []
     *     Paths that must never appear anywhere.
     *
     * $allowedColumns = []
     *     When set, an exhaustive allowlist of exposed paths.
     *
     * $labels = []
     *     Extra label overrides keyed by path.
     *
     * $defaultSort = []
     *     Default sort, e.g. ['created_at' => 'desc'].
     *
     * $scopes = []
     *     Eloquent scopes always applied to the base query.
     *
     * $params = []
     *     External parameters this table accepts — the values behind your own
     *     controls: a date range, a branch picker, a report id. Declare them as
     *     a list of names, or as a map of name => default:
     *
     *         protected $params = ['from_date', 'to_date', 'status' => 'open'];
     *
     *     Only declared names are accepted, from the page request on first
     *     paint and from the browser on every refresh afterwards. Read them
     *     inside query() with $this->param('from_date').
     *
     * $paramFilters = []
     *     Parameters bound straight to the query, instead of by hand in
     *     query():
     *
     *         protected $paramFilters = [
     *             'status',                                     // where('status', $value)
     *             'category' => 'company_category_id',           // parameter name is not the column
     *             'q' => ['column' => 'name', 'operator' => 'contains'],
     *             'area' => ['column' => 'companyArea.slug'],    // through a relation
     *             'created_period' => ['column' => 'created_at', 'operator' => 'period'],
     *         ];
     *
     *     A filter is applied only when its parameter arrived with a value, and
     *     every name here is a declared parameter, so $params need not repeat
     *     them. See Filters\ParamFilters for the operators and the period
     *     vocabulary.
     *
     *     PHP does not allow a closure in a property default, so a filter that
     *     needs one — anything the operators cannot say — comes from the method
     *     instead:
     *
     *         public function paramFilters()
     *         {
     *             return [
     *                 ...parent::paramFilters(),
     *                 'agent' => fn (Builder $q, $value) => $q->whereHas('agent', ...),
     *             ];
     *         }
     *
     * $with = []
     *     Relations to always eager load in addition to the detected ones.
     *
     * $pagination = 'auto'
     *     "length_aware" counts the whole result set so the UI can show a total
     *     and numbered pages. "simple" fetches one extra row instead, giving
     *     previous and next only — the right trade at tens of millions of rows,
     *     where the COUNT(*) costs more than the page itself. "auto" picks
     *     length-aware until the table grows past
     *     config('dynamic-table.pagination.count_threshold'). "infinite"
     *     appends pages and never counts.
     *
     * $perPage = null
     *     Rows per page. Null follows
     *     config('dynamic-table.pagination.default').
     *
     * $perPageOptions = []
     *     The choices offered in the page-size menu.
     *
     * $maxHeight = null
     *     How tall the table's scroll area may grow — any CSS length, e.g.
     *     "70vh". This is what makes the sticky header stick: a header can only
     *     stay put inside a box that scrolls, so the table needs a height of
     *     its own. 'none' restores page-flow height, and the header then
     *     scrolls away with the rest of the page.
     *
     * $printView = null
     *     A print template for this table only. Null follows the config.
     *
     * $printStylesheets = []
     *     Stylesheets the print page should load, before its own.
     *
     * $theme = null
     *     Null follows config('dynamic-table.theme').
     *
     * $direction = null
     *     "ltr", "rtl", or null to follow the application locale.
     *
     * $scheme = null
     *     "light", "dark", or null to follow the viewer's operating system.
     *
     * $panels = null
     *     How panels are presented: "modal", "offcanvas", or null to follow
     *     config('dynamic-table.panels.mode').
     *
     * $responsive = null
     *     Small-screen behaviour, or null to follow
     *     config('dynamic-table.responsive.mode'). "collapse" hides the columns
     *     that do not fit and reveals them in an expandable child row, the way
     *     DataTables Responsive and PowerGrid do. "scroll" keeps every column
     *     and scrolls horizontally. "cards" stacks each row into a labelled
     *     card. "none" switches the handling off for this table.
     *
     * $responsiveFixed = []
     *     Column paths that must never collapse. Defaults to the first column.
     *
     * $stickyColumns = []
     *     Column paths pinned in place while the table scrolls sideways. Needs
     *     the sticky_columns feature.
     *
     * $stickyActions = false
     *     Pin the row-actions column to the trailing edge as well.
     *
     * $filterCounts = []
     *     Root column paths that report counts in their filter options. Needs
     *     the filter_counts feature, and costs one grouped query per opened
     *     dropdown.
     *
     * $relationDepth = 1
     *     How deep the filter builder and column picker may walk relationships.
     *     Switch the "relations" feature off to stop them walking any, without
     *     touching this number.
     *
     * $policy = null
     *     Optional policy/gate ability prefix, e.g. "users" => "viewAny users".
     */

    /** @var array<string, mixed> The validated values for this request. */
    private array $resolvedParams = [];

    /**
     * Customise the base query.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function query(Builder $query)
    {
        return $query;
    }

    /**
     * Explicit column configuration. Return [] to keep automatic discovery.
     *
     * @return array<int|string, mixed>
     */
    protected function columns()
    {
        return [];
    }

    /**
     * Bulk actions offered when rows are selected.
     *
     * @return list<BulkAction>
     */
    public function actions()
    {
        return [];
    }

    /**
     * Buttons shown on every row.
     *
     * @return list<RowAction>
     */
    public function rowActions()
    {
        return [];
    }

    /**
     * Buttons in the table's own toolbar — "New product", "Sync catalogue".
     *
     * @return list<ToolbarAction>
     */
    public function toolbar()
    {
        return [];
    }

    /**
     * The expanded detail for one row.
     *
     * Return a string, an Htmlable, or a Blade view. Fetched on demand when the
     * row is expanded, so a page of fifty rows never renders fifty details.
     *
     * @return mixed
     */
    public function rowDetail(Model $record)
    {
        return null;
    }

    /**
     * Attributes a newly created record starts with, before the user's input.
     *
     * @return array<string, mixed>
     */
    public function newRecordDefaults()
    {
        return [];
    }

    /**
     * Validation rules used by inline editing and import, keyed by field path.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [];
    }

    /** @return array<string, string> */
    public function validationMessages()
    {
        return [];
    }

    /**
     * Developer-defined presets, exposed alongside saved system views.
     *
     * @return array<string, array<string, mixed>>
     */
    public function presets()
    {
        return [];
    }

    /**
     * Authorisation hook. Return null to fall back to the model policy when
     * one exists, or to "allowed" when it does not.
     *
     * @return bool|null
     */
    public function authorize(string $ability, ?Model $record = null)
    {
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Internal API — used by the engines, stable but not for extension */
    /* ------------------------------------------------------------------ */

    private ?FeatureSet $featureSet = null;

    /** @var array<string, ColumnDefinition>|null */
    private ?array $resolvedColumns = null;

    /**
     * Columns the picker added that the table never declared, memoised per
     * request so one page does not resolve the same path repeatedly.
     *
     * @var array<string, ColumnDefinition|null>
     */
    private array $adHocColumns = [];

    private ?ModelMetadata $metadataCache = null;

    /** @return string */
    public function key()
    {
        if (($this->tableKey ?? null) !== null) {
            return (string) $this->tableKey;
        }

        $base = class_basename(static::class);
        $base = (string) Str::of($base)->beforeLast('Table');

        return Str::snake($base === '' ? class_basename(static::class) : $base);
    }

    /** @return string */
    public function title()
    {
        if (($this->title ?? null) !== null) {
            return (string) $this->title;
        }

        return Str::headline(Str::plural($this->key()));
    }

    /** @return class-string<Model> */
    public function modelClass()
    {
        $model = $this->model ?? null;

        if (! is_string($model) || $model === '') {
            throw DynamicTableException::missingModel(static::class);
        }

        return $model;
    }

    /** @return Model */
    public function newModel()
    {
        $class = $this->modelClass();

        return new $class;
    }

    /** @return ModelMetadata */
    public function metadata()
    {
        return $this->metadataCache ??= app(MetadataEngine::class)->for($this->modelClass());
    }

    /** @return FeatureSet */
    public function features()
    {
        return $this->featureSet ??= new FeatureSet($this->features ?? [], static::class);
    }

    /** @return bool */
    public function hasFeature(string $feature)
    {
        return $this->features()->has($feature);
    }

    public function requireFeature(string $feature)
    {
        if (! $this->hasFeature($feature)) {
            // Forbidden, not a server error: the endpoint exists and the
            // request is well formed, this table simply does not offer the
            // feature. A 500 would also leak a stack trace for what is a
            // routine "not for this table" answer.
            abort(403, "The [{$feature}] feature is not enabled for this table.");
        }
    }

    /** @return array<int|string, mixed> */
    public function columnDefinitions()
    {
        $columns = $this->columns ?? [];

        return $columns !== [] ? $columns : $this->columns();
    }

    /** @return array<string, ColumnDefinition> */
    public function resolvedColumns()
    {
        return $this->resolvedColumns ??= app(ColumnResolver::class)->resolve($this);
    }

    /** @return ColumnDefinition|null */
    public function column(string $key)
    {
        return $this->resolvedColumns()[$key] ?? null;
    }

    /**
     * A column by key, including one the table never declared.
     *
     * The column picker offers everything the metadata engine can reach — the
     * model's own fields and those of its singular relations — the way a
     * Dynamics view lets you add any column of the entity or its lookups. A
     * column chosen that way was never in columns(), so it is built on demand
     * here rather than being rejected.
     *
     * The same three gates as everywhere else still apply, and they are the
     * reason this is safe: the path must resolve through the metadata engine
     * (so it exists, is not hidden by the model, and is within the relation
     * depth), it must not be in $hiddenColumns, and it must pass
     * $allowedColumns when that list is set. A crafted key gets nothing that a
     * filter or a sort could not already have reached.
     *
     * @return ColumnDefinition|null
     */
    public function columnFor(string $key)
    {
        $declared = $this->resolvedColumns()[$key] ?? null;

        if ($declared !== null) {
            return $declared;
        }

        if (! $this->hasFeature(Feature::COLUMN_PICKER)) {
            return null;
        }

        return $this->adHocColumns[$key] ??= $this->buildAdHocColumn($key);
    }

    /** @return ColumnDefinition|null */
    protected function buildAdHocColumn(string $key)
    {
        $path = str_replace('__', '.', $key);

        if (in_array($path, $this->hiddenColumnPaths(), true)) {
            return null;
        }

        $allowed = $this->allowedColumnPaths();

        if ($allowed !== [] && ! in_array($path, $allowed, true)) {
            return null;
        }

        // Depth is checked against this table's own limit, not just the global
        // one the metadata engine enforces.
        if (substr_count($path, '.') > $this->relationDepth()) {
            return null;
        }

        $field = app(MetadataEngine::class)->resolve($this->modelClass(), $path);

        if ($field === null) {
            return null;
        }

        return app(ColumnResolver::class)->one($this, $field);
    }

    /** @return list<string> */
    public function allowedColumnPaths()
    {
        return $this->allowedColumns ?? [];
    }

    /** @return list<string> */
    public function hiddenColumnPaths()
    {
        return $this->hiddenColumns ?? [];
    }

    /** @return string|null */
    public function labelFor(string $path)
    {
        if (isset($this->labels[$path])) {
            return (string) $this->labels[$path];
        }

        $translation = 'dynamic-table::fields.'.$this->key().'.'.$path;

        if (trans()->has($translation)) {
            return (string) trans($translation);
        }

        return null;
    }

    /**
     * Fields the global search box queries.
     *
     * With no explicit list, pick short indexed-or-textual columns and stop at
     * a configured maximum so a search never fans out into a table scan across
     * every string column in the schema.
     *
     * @return list<string>
     */
    public function searchablePaths()
    {
        $searchable = $this->searchable ?? [];

        if ($searchable !== []) {
            return array_values(array_filter(
                $searchable,
                fn (string $path): bool => app(MetadataEngine::class)->resolve($this->modelClass(), $path)?->isSearchable() === true,
            ));
        }

        $max = (int) config('dynamic-table.search.max_auto_columns', 6);
        $paths = [];

        foreach ($this->resolvedColumns() as $column) {
            if (count($paths) >= $max) {
                break;
            }

            if (! $column->visible || ! $column->searchable) {
                continue;
            }

            $paths[] = $column->path();
        }

        return $paths;
    }

    /** @return array<string, string> */
    public function defaultSort()
    {
        $sort = $this->defaultSort ?? [];

        if ($sort !== []) {
            return $sort;
        }

        $meta = $this->metadata();

        foreach (['created_at', $meta->keyName] as $candidate) {
            if ($meta->has($candidate)) {
                return [$candidate => 'desc'];
            }
        }

        return [];
    }

    /**
     * Whether this request should count the full result set.
     *
     * "auto" asks the database for its own row estimate — which is free, unlike
     * COUNT(*) — and drops to simple pagination past the threshold.
     *
     * @return bool
     */
    public function countsRows()
    {
        $pagination = $this->pagination ?? 'auto';

        $mode = in_array($pagination, ['length_aware', 'simple', 'auto', 'infinite'], true)
            ? $pagination
            : 'auto';

        // Infinite scrolling appends pages, so a total would only ever be shown
        // once and then be wrong; it never counts.
        if ($mode !== 'auto') {
            return $mode === 'length_aware';
        }

        $threshold = (int) config('dynamic-table.pagination.count_threshold', 250000);

        if ($threshold <= 0) {
            return true;
        }

        return app(TableEstimator::class)->rows($this->newModel()) <= $threshold;
    }

    /**
     * The Blade view the print button opens.
     *
     * Publishing the views puts an editable copy at
     * resources/views/vendor/dynamic-table/print.blade.php; override this to
     * give one table a template of its own.
     *
     * @return string
     */
    public function printView()
    {
        return (string) ($this->printView ?? config('dynamic-table.print.view', 'dynamic-table::print'));
    }

    /** @return list<string> */
    public function printStylesheets()
    {
        return $this->printStylesheets ?? [];
    }

    /**
     * The scroll area's height, or null to let the page own the scrolling.
     *
     * @return string|null
     */
    public function maxHeight()
    {
        $value = $this->maxHeight ?? config('dynamic-table.table.max_height');

        if (! is_string($value) || $value === '' || $value === 'none') {
            return null;
        }

        return $value;
    }

    /**
     * How pages are presented: numbered pages, or appended as you scroll.
     *
     * Infinite scrolling is a presentation choice on top of the same
     * server-side paging — there is no separate "load everything" path.
     *
     * @return string
     */
    public function paginationStyle()
    {
        return ($this->pagination ?? 'auto') === 'infinite' ? 'infinite' : 'pages';
    }

    /** @return int */
    public function perPage()
    {
        return (int) ($this->perPage ?? config('dynamic-table.pagination.default', 25));
    }

    /** @return list<int> */
    public function perPageOptions()
    {
        $options = ($this->perPageOptions ?? []) !== []
            ? $this->perPageOptions
            : (array) config('dynamic-table.pagination.options', [10, 25, 50, 100]);

        $options = array_values(array_unique(array_map('intval', $options)));
        sort($options);

        return $options;
    }

    /** @return string */
    public function theme()
    {
        return (string) ($this->theme ?? config('dynamic-table.theme', 'tailwind'));
    }

    /** @return string */
    public function direction()
    {
        if (($this->direction ?? null) !== null) {
            return (string) $this->direction;
        }

        $configured = config('dynamic-table.direction');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'yi'], true) ? 'rtl' : 'ltr';
    }

    /**
     * The forced colour scheme, or null to follow prefers-color-scheme.
     *
     * Themes contribute no colour of their own, so this one value controls the
     * table's appearance identically under Bootstrap, Tailwind and custom
     * themes.
     *
     * @return string|null
     */
    public function scheme()
    {
        $scheme = $this->scheme ?? config('dynamic-table.scheme');

        return in_array($scheme, ['light', 'dark'], true) ? $scheme : null;
    }

    /**
     * The resolved responsive configuration, or null when it is switched off.
     *
     * @return array{mode: string, fixed: list<string>, breakpoint: int}|null
     */
    public function responsive()
    {
        // Three independent off switches, in order of scope: the application
        // config, this table's feature list, and this table's own mode.
        if (! config('dynamic-table.responsive.enabled', true)) {
            return null;
        }

        if (! $this->hasFeature(Feature::RESPONSIVE)) {
            return null;
        }

        $mode = $this->responsive ?? config('dynamic-table.responsive.mode', 'collapse');

        if (! in_array($mode, ['collapse', 'scroll', 'cards'], true)) {
            return null;
        }

        $resolver = app(ColumnResolver::class);
        $fixed = array_map(
            static fn (string $path): string => $resolver->keyFor($path),
            $this->responsiveFixed ?? [],
        );

        // With nothing declared, the first visible column anchors the row —
        // collapsing it would leave a row with no way to identify itself.
        if ($fixed === []) {
            foreach ($this->resolvedColumns() as $key => $column) {
                if ($column->visible) {
                    $fixed = [$key];

                    break;
                }
            }
        }

        return [
            'mode' => $mode,
            'fixed' => array_values($fixed),
            'breakpoint' => (int) config('dynamic-table.responsive.breakpoint', 640),
        ];
    }

    /**
     * How the filter builder, column picker and other panels are presented.
     *
     * "start"/"end" are resolved to a physical side here so the stylesheet does
     * not have to reason about direction.
     *
     * @return array{mode: string, side: string, width: string}
     */
    public function panels()
    {
        $mode = $this->panels ?? config('dynamic-table.panels.mode', 'modal');
        $mode = in_array($mode, ['modal', 'offcanvas'], true) ? $mode : 'modal';

        $side = (string) config('dynamic-table.panels.side', 'end');

        $side = match ($side) {
            'start' => $this->direction() === 'rtl' ? 'right' : 'left',
            'left', 'right' => $side,
            default => $this->direction() === 'rtl' ? 'left' : 'right',
        };

        return [
            'mode' => $mode,
            'side' => $side,
            'width' => (string) config('dynamic-table.panels.width', '30rem'),
        ];
    }

    /**
     * How deep a reader may walk relationships.
     *
     * Three things ask: the field catalogue behind the filter builder and the
     * column picker, a column the picker added that the table never declared,
     * and a filter condition on a relation path. Switching the relations
     * feature off answers 0 to all three at once, which is what makes it a
     * single switch rather than three.
     *
     * @return int
     */
    public function relationDepth()
    {
        if (! $this->hasFeature(Feature::RELATIONS)) {
            return 0;
        }

        return max(0, min((int) ($this->relationDepth ?? 1), (int) config('dynamic-table.security.max_relation_depth', 3)));
    }

    /** @return list<string> */
    public function eagerLoad()
    {
        return $this->with ?? [];
    }

    /** @return list<string> */
    public function scopes()
    {
        return $this->scopes ?? [];
    }

    /**
     * Filters declared as parameter bindings.
     *
     * @return array<int|string, mixed>
     */
    public function paramFilters()
    {
        return $this->paramFilters ?? [];
    }

    /**
     * The parameters this table declares, as name => default.
     *
     * Anything a declared filter reads is a parameter too, so binding one does
     * not also have to be written down in $params.
     *
     * @return array<string, mixed>
     */
    public function declaredParams()
    {
        $declared = [];

        foreach (ParamFilters::parameters($this->paramFilters()) as $name) {
            $declared[$name] = null;
        }

        foreach (($this->params ?? []) as $name => $default) {
            if (is_int($name)) {
                $declared[(string) $default] = null;
            } else {
                $declared[$name] = $default;
            }
        }

        return $declared;
    }

    /**
     * Adopt the validated parameters for this request.
     *
     * Called by TableState once the incoming values have been checked against
     * declaredParams(), so query() and the action hooks can read them from the
     * table itself rather than from the request.
     *
     * @param  array<string, mixed>  $params
     * @return static
     */
    public function useParams(array $params)
    {
        $this->resolvedParams = $params;

        return $this;
    }

    /** @return array<string, mixed> */
    public function params()
    {
        return $this->resolvedParams + $this->declaredParams();
    }

    /**
     * One parameter, falling back to its declared default.
     *
     * @return mixed
     */
    public function param(string $name, mixed $default = null)
    {
        $value = $this->params()[$name] ?? null;

        return $value === null || $value === '' || $value === [] ? $default : $value;
    }

    /**
     * Whether a parameter arrived with a usable value.
     *
     * @return bool
     */
    public function hasParam(string $name)
    {
        return $this->param($name) !== null;
    }

    /**
     * Resolve an ability for this table.
     *
     * Order: the table's own authorize() hook, then a model policy if one is
     * registered, then allow. Anything that mutates data (update/delete/import)
     * defaults to denied when no policy exists and no hook answers.
     *
     * @return bool
     */
    public function can(string $ability, ?Model $record = null)
    {
        $answer = $this->authorize($ability, $record);

        if ($answer !== null) {
            return $answer;
        }

        $subject = $record ?? $this->modelClass();

        if (Gate::getPolicyFor($this->modelClass()) !== null) {
            return Gate::allows($this->policyAbility($ability), $subject);
        }

        if (($this->policy ?? null) !== null) {
            return Gate::allows($this->policy.'.'.$ability, $subject);
        }

        return true;
    }

    /** @return string */
    protected function policyAbility(string $ability)
    {
        return match ($ability) {
            'view' => 'viewAny',
            'edit', 'inline-edit' => 'update',
            'bulk-delete' => 'delete',
            'export' => 'viewAny',
            'import' => 'create',
            default => $ability,
        };
    }

    /**
     * @return list<BulkAction>
     */
    public function availableActions()
    {
        if (! $this->hasFeature(Feature::BULK_ACTIONS)) {
            return [];
        }

        return array_values(array_filter(
            $this->actions(),
            fn (BulkAction $action): bool => $action->isAuthorized($this),
        ));
    }

    /** @return BulkAction|null */
    public function findAction(string $name)
    {
        foreach ($this->availableActions() as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Row actions this viewer could ever see.
     *
     * Per-record visibility is decided later, once there is a record to ask
     * about; this is only the table-level filter.
     *
     * @return list<RowAction>
     */
    public function availableRowActions()
    {
        if (! $this->hasFeature(Feature::ROW_ACTIONS)) {
            return [];
        }

        return array_values(array_filter(
            $this->rowActions(),
            fn (RowAction $action): bool => $action->isAuthorized($this),
        ));
    }

    /** @return list<ToolbarAction> */
    public function availableToolbarActions()
    {
        if (! $this->hasFeature(Feature::TOOLBAR_ACTIONS)) {
            return [];
        }

        return array_values(array_filter(
            $this->toolbar(),
            fn (ToolbarAction $action): bool => $action->isAvailable($this),
        ));
    }

    /** @return ToolbarAction|null */
    public function findToolbarAction(string $name)
    {
        foreach ($this->availableToolbarActions() as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Columns pinned while the table scrolls sideways, as column keys.
     *
     * @return list<string>
     */
    public function stickyColumnKeys()
    {
        if (! $this->hasFeature(Feature::STICKY_COLUMNS)) {
            return [];
        }

        $resolver = app(ColumnResolver::class);
        $resolved = $this->resolvedColumns();

        return array_values(array_filter(
            array_map(static fn (string $path): string => $resolver->keyFor($path), $this->stickyColumns ?? []),
            static fn (string $key): bool => isset($resolved[$key]),
        ));
    }

    /** @return bool */
    public function hasStickyActions()
    {
        return ($this->stickyActions ?? false) && $this->hasFeature(Feature::STICKY_COLUMNS);
    }

    /**
     * Column keys that report counts in their filter options.
     *
     * @return list<string>
     */
    public function filterCountKeys()
    {
        if (! $this->hasFeature(Feature::FILTER_COUNTS)) {
            return [];
        }

        $resolver = app(ColumnResolver::class);
        $resolved = $this->resolvedColumns();

        return array_values(array_filter(
            array_map(static fn (string $path): string => $resolver->keyFor($path), $this->filterCounts ?? []),
            static fn (string $key): bool => isset($resolved[$key]) && ! $resolved[$key]->isRelational(),
        ));
    }

    /**
     * Columns a user may fill in when creating or bulk-editing.
     *
     * @return array
     */
    public function editableColumns()
    {
        return array_filter(
            $this->resolvedColumns(),
            static fn (ColumnDefinition $column): bool => $column->editable,
        );
    }

    /** @return RowAction|null */
    public function findRowAction(string $name)
    {
        foreach ($this->availableRowActions() as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }
}

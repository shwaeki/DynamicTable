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
 *         protected string $model = User::class;
 *     }
 *
 * Everything below is either a sensible default or an optional override.
 */
abstract class DynamicTable
{
    /* ------------------------------------------------------------------ */
    /* Public API — override these in your table class */
    /* ------------------------------------------------------------------ */

    /** @var class-string<Model> */
    protected string $model;

    /** Stable identifier used by saved views and the data endpoint. */
    protected ?string $tableKey = null;

    /** Human title shown above the table. Defaults to the model's plural name. */
    protected ?string $title = null;

    /** @var list<string> Opt-in features. Prefix with "-" to switch a default off. */
    protected array $features = [];

    /** @var array<int|string, mixed> Explicit column list. Empty means "discover them". */
    protected array $columns = [];

    /** @var list<string> Fields the global search box looks at. Empty means "pick safe ones". */
    protected array $searchable = [];

    /** @var list<string> Paths that must never appear anywhere. */
    protected array $hiddenColumns = [];

    /** @var list<string> When set, an exhaustive allowlist of exposed paths. */
    protected array $allowedColumns = [];

    /** @var array<string, string> Extra label overrides keyed by path. */
    protected array $labels = [];

    /** @var array<string, string> Default sort, e.g. ['created_at' => 'desc'] */
    protected array $defaultSort = [];

    /** @var list<string> Eloquent scopes always applied to the base query. */
    protected array $scopes = [];

    /** @var list<string> Relations to always eager load in addition to the detected ones. */
    protected array $with = [];

    /**
     * "length_aware" counts the whole result set so the UI can show a total and
     * numbered pages. "simple" fetches one extra row instead, giving previous
     * and next only — the right trade at tens of millions of rows, where the
     * COUNT(*) costs more than the page itself. "auto" picks length-aware until
     * the table grows past config('dynamic-table.pagination.count_threshold').
     */
    protected string $pagination = 'auto';

    protected ?int $perPage = null;

    /** @var list<int> */
    protected array $perPageOptions = [];

    /**
     * How tall the table's scroll area may grow — any CSS length, e.g. "70vh".
     *
     * This is what makes the sticky header stick: a header can only stay put
     * inside a box that scrolls, so the table needs a height of its own.
     * `'none'` restores page-flow height, and the header then scrolls away with
     * the rest of the page.
     */
    protected ?string $maxHeight = null;

    protected ?string $theme = null;

    /** "ltr", "rtl", or null to follow the application locale. */
    protected ?string $direction = null;

    /** "light", "dark", or null to follow the viewer's operating system. */
    protected ?string $scheme = null;

    /**
     * How panels are presented: "modal", "offcanvas", or null to follow
     * config('dynamic-table.panels.mode').
     */
    protected ?string $panels = null;

    /**
     * Small-screen behaviour, or null to follow config('dynamic-table.responsive.mode').
     *
     * "collapse" hides the columns that do not fit and reveals them in an
     * expandable child row, the way DataTables Responsive and PowerGrid do.
     * "scroll" keeps every column and scrolls horizontally. "cards" stacks each
     * row into a labelled card. "none" switches the handling off for this table.
     */
    protected ?string $responsive = null;

    /** @var list<string> Column paths that must never collapse. Defaults to the first column. */
    protected array $responsiveFixed = [];

    /**
     * @var list<string> Column paths pinned in place while the table scrolls
     *                   sideways. Needs the sticky_columns feature.
     */
    protected array $stickyColumns = [];

    /** Pin the row-actions column to the trailing edge as well. */
    protected bool $stickyActions = false;

    /**
     * @var list<string> Root column paths that report counts in their filter
     *                   options. Needs the facets feature, and costs one
     *                   grouped query per opened dropdown.
     */
    protected array $facets = [];

    /** How deep the filter builder and column picker may walk relationships. */
    protected int $relationDepth = 1;

    /** Optional policy/gate ability prefix, e.g. "users" => "viewAny users". */
    protected ?string $policy = null;

    /**
     * Customise the base query.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function query(Builder $query): Builder
    {
        return $query;
    }

    /**
     * Explicit column configuration. Return [] to keep automatic discovery.
     *
     * @return array<int|string, mixed>
     */
    protected function columns(): array
    {
        return [];
    }

    /**
     * Bulk actions offered when rows are selected.
     *
     * @return list<BulkAction>
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Buttons shown on every row.
     *
     * @return list<RowAction>
     */
    public function rowActions(): array
    {
        return [];
    }

    /**
     * Buttons in the table's own toolbar — "New product", "Sync catalogue".
     *
     * @return list<ToolbarAction>
     */
    public function toolbar(): array
    {
        return [];
    }

    /**
     * The expanded detail for one row.
     *
     * Return a string, an Htmlable, or a Blade view. Fetched on demand when the
     * row is expanded, so a page of fifty rows never renders fifty details.
     */
    public function rowDetail(Model $record): mixed
    {
        return null;
    }

    /**
     * Attributes a newly created record starts with, before the user's input.
     *
     * @return array<string, mixed>
     */
    public function newRecordDefaults(): array
    {
        return [];
    }

    /**
     * Validation rules used by inline editing and import, keyed by field path.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function validationMessages(): array
    {
        return [];
    }

    /**
     * Developer-defined presets, exposed alongside saved system views.
     *
     * @return array<string, array<string, mixed>>
     */
    public function presets(): array
    {
        return [];
    }

    /**
     * Authorisation hook. Return null to fall back to the model policy when
     * one exists, or to "allowed" when it does not.
     */
    public function authorize(string $ability, ?Model $record = null): ?bool
    {
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Internal API — used by the engines, stable but not for extension */
    /* ------------------------------------------------------------------ */

    private ?FeatureSet $featureSet = null;

    /** @var array<string, ColumnDefinition>|null */
    private ?array $resolvedColumns = null;

    private ?ModelMetadata $metadataCache = null;

    public function key(): string
    {
        if ($this->tableKey !== null) {
            return $this->tableKey;
        }

        $base = class_basename(static::class);
        $base = (string) Str::of($base)->beforeLast('Table');

        return Str::snake($base === '' ? class_basename(static::class) : $base);
    }

    public function title(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }

        return Str::headline(Str::plural($this->key()));
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        if (! isset($this->model)) {
            throw DynamicTableException::missingModel(static::class);
        }

        return $this->model;
    }

    public function newModel(): Model
    {
        $class = $this->modelClass();

        return new $class;
    }

    public function metadata(): ModelMetadata
    {
        return $this->metadataCache ??= app(MetadataEngine::class)->for($this->modelClass());
    }

    public function features(): FeatureSet
    {
        return $this->featureSet ??= new FeatureSet($this->features);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->features()->has($feature);
    }

    public function requireFeature(string $feature): void
    {
        if (! $this->hasFeature($feature)) {
            throw DynamicTableException::featureDisabled($feature);
        }
    }

    /** @return array<int|string, mixed> */
    public function columnDefinitions(): array
    {
        return $this->columns !== [] ? $this->columns : $this->columns();
    }

    /** @return array<string, ColumnDefinition> */
    public function resolvedColumns(): array
    {
        return $this->resolvedColumns ??= app(ColumnResolver::class)->resolve($this);
    }

    public function column(string $key): ?ColumnDefinition
    {
        return $this->resolvedColumns()[$key] ?? null;
    }

    /** @return list<string> */
    public function allowedColumnPaths(): array
    {
        return $this->allowedColumns;
    }

    /** @return list<string> */
    public function hiddenColumnPaths(): array
    {
        return $this->hiddenColumns;
    }

    public function labelFor(string $path): ?string
    {
        if (isset($this->labels[$path])) {
            return $this->labels[$path];
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
    public function searchablePaths(): array
    {
        if ($this->searchable !== []) {
            return array_values(array_filter(
                $this->searchable,
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
    public function defaultSort(): array
    {
        if ($this->defaultSort !== []) {
            return $this->defaultSort;
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
     */
    public function countsRows(): bool
    {
        $mode = in_array($this->pagination, ['length_aware', 'simple', 'auto', 'infinite'], true)
            ? $this->pagination
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
     * How pages are presented: numbered pages, or appended as you scroll.
     *
     * Infinite scrolling is a presentation choice on top of the same
     * server-side paging — there is no separate "load everything" path.
     */
    /** The scroll area's height, or null to let the page own the scrolling. */
    public function maxHeight(): ?string
    {
        $value = $this->maxHeight ?? config('dynamic-table.table.max_height');

        if (! is_string($value) || $value === '' || $value === 'none') {
            return null;
        }

        return $value;
    }

    public function paginationStyle(): string
    {
        return $this->pagination === 'infinite' ? 'infinite' : 'pages';
    }

    public function perPage(): int
    {
        return $this->perPage ?? (int) config('dynamic-table.pagination.default', 25);
    }

    /** @return list<int> */
    public function perPageOptions(): array
    {
        $options = $this->perPageOptions !== []
            ? $this->perPageOptions
            : (array) config('dynamic-table.pagination.options', [10, 25, 50, 100]);

        $options = array_values(array_unique(array_map('intval', $options)));
        sort($options);

        return $options;
    }

    public function theme(): string
    {
        return $this->theme ?? (string) config('dynamic-table.theme', 'tailwind');
    }

    public function direction(): string
    {
        if ($this->direction !== null) {
            return $this->direction;
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
     */
    public function scheme(): ?string
    {
        $scheme = $this->scheme ?? config('dynamic-table.scheme');

        return in_array($scheme, ['light', 'dark'], true) ? $scheme : null;
    }

    /**
     * The resolved responsive configuration, or null when it is switched off.
     *
     * @return array{mode: string, fixed: list<string>, breakpoint: int}|null
     */
    public function responsive(): ?array
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
            $this->responsiveFixed,
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
    public function panels(): array
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

    public function relationDepth(): int
    {
        return max(0, min($this->relationDepth, (int) config('dynamic-table.security.max_relation_depth', 3)));
    }

    /** @return list<string> */
    public function eagerLoad(): array
    {
        return $this->with;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function usesSoftDeletes(): bool
    {
        return $this->hasFeature(Feature::SOFT_DELETES) && $this->metadata()->usesSoftDeletes;
    }

    /**
     * Resolve an ability for this table.
     *
     * Order: the table's own authorize() hook, then a model policy if one is
     * registered, then allow. Anything that mutates data (update/delete/import)
     * defaults to denied when no policy exists and no hook answers.
     */
    public function can(string $ability, ?Model $record = null): bool
    {
        $answer = $this->authorize($ability, $record);

        if ($answer !== null) {
            return $answer;
        }

        $subject = $record ?? $this->modelClass();

        if (Gate::getPolicyFor($this->modelClass()) !== null) {
            return Gate::allows($this->policyAbility($ability), $subject);
        }

        if ($this->policy !== null) {
            return Gate::allows($this->policy.'.'.$ability, $subject);
        }

        return true;
    }

    protected function policyAbility(string $ability): string
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
    public function availableActions(): array
    {
        if (! $this->hasFeature(Feature::BULK_ACTIONS)) {
            return [];
        }

        return array_values(array_filter(
            $this->actions(),
            fn (BulkAction $action): bool => $action->isAuthorized($this),
        ));
    }

    public function findAction(string $name): ?BulkAction
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
    public function availableRowActions(): array
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
    public function availableToolbarActions(): array
    {
        if (! $this->hasFeature(Feature::TOOLBAR_ACTIONS)) {
            return [];
        }

        return array_values(array_filter(
            $this->toolbar(),
            fn (ToolbarAction $action): bool => $action->isAvailable($this),
        ));
    }

    public function findToolbarAction(string $name): ?ToolbarAction
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
    public function stickyColumnKeys(): array
    {
        if (! $this->hasFeature(Feature::STICKY_COLUMNS)) {
            return [];
        }

        $resolver = app(ColumnResolver::class);
        $resolved = $this->resolvedColumns();

        return array_values(array_filter(
            array_map(static fn (string $path): string => $resolver->keyFor($path), $this->stickyColumns),
            static fn (string $key): bool => isset($resolved[$key]),
        ));
    }

    public function hasStickyActions(): bool
    {
        return $this->stickyActions && $this->hasFeature(Feature::STICKY_COLUMNS);
    }

    /**
     * Column keys that report counts in their filter options.
     *
     * @return list<string>
     */
    public function facetKeys(): array
    {
        if (! $this->hasFeature(Feature::FACETS)) {
            return [];
        }

        $resolver = app(ColumnResolver::class);
        $resolved = $this->resolvedColumns();

        return array_values(array_filter(
            array_map(static fn (string $path): string => $resolver->keyFor($path), $this->facets),
            static fn (string $key): bool => isset($resolved[$key]) && ! $resolved[$key]->isRelational(),
        ));
    }

    /** Columns a user may fill in when creating or bulk-editing. */
    public function editableColumns(): array
    {
        return array_filter(
            $this->resolvedColumns(),
            static fn (ColumnDefinition $column): bool => $column->editable,
        );
    }

    public function findRowAction(string $name): ?RowAction
    {
        foreach ($this->availableRowActions() as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }
}

<?php

namespace Shwaeki\DynamicTable\Support;

use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Filters\FilterEngine;
use Shwaeki\DynamicTable\Filters\FilterGroup;

/**
 * The validated, server-owned view of what the client asked for.
 *
 * Everything here has been checked against the table's own metadata, so the
 * query engine can consume it without further defensive checks. Unknown
 * columns, sorts and filters are dropped rather than fatal — a saved view that
 * references a since-removed field degrades instead of breaking the page.
 */
final class TableState
{
    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param  list<string>  $columns  ordered, visible column keys
     * @param  list<array{field: string, direction: string}>  $sort
     * @param  array<string, string>  $columnSearch
     * @param  array<string, int>  $widths
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $params  declared, table-specific parameters
     */
    private function __construct(
        public readonly string $search = '',
        public readonly array $columnSearch = [],
        public readonly FilterGroup $filters = new FilterGroup,
        public readonly array $rawFilters = [],
        public readonly array $sort = [],
        public readonly int $page = 1,
        public readonly int $perPage = 25,
        public readonly array $columns = [],
        public readonly array $widths = [],
        public readonly ?string $group = null,
        public readonly string $trashed = 'without',
        public readonly array $selection = [],
        public readonly ?string $view = null,
        public readonly array $params = [],
    ) {}

    /**
     * @param  array<string, mixed>  $input  untrusted
     */
    public static function fromArray(array $input, DynamicTable $table): self
    {
        $features = $table->features();

        $search = $features->has(Feature::SEARCH) ? trim((string) ($input['search'] ?? '')) : '';
        $search = mb_substr($search, 0, 200);

        $columnSearch = [];

        if ($features->has(Feature::COLUMN_SEARCH) && is_array($input['columnSearch'] ?? null)) {
            foreach ($input['columnSearch'] as $key => $value) {
                if (! is_string($key) || $table->columnFor($key) === null || ! is_scalar($value)) {
                    continue;
                }

                $value = trim((string) $value);

                if ($value !== '') {
                    $columnSearch[$key] = mb_substr($value, 0, 200);
                }
            }
        }

        $filterEngine = app(FilterEngine::class);
        $rawFilters = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $filters = $features->has(Feature::FILTERS)
            ? $filterEngine->parse($rawFilters, $table)
            : new FilterGroup;

        $sort = self::normalizeSort($input['sort'] ?? null, $table);

        $perPageOptions = $table->perPageOptions();
        $perPage = (int) ($input['perPage'] ?? $table->perPage());

        if (! $features->has(Feature::PAGINATION)) {
            $perPage = min((int) config('dynamic-table.pagination.max', 500), max($perPageOptions));
        } elseif (! in_array($perPage, $perPageOptions, true)) {
            $perPage = $table->perPage();
        }

        $columns = self::normalizeColumns($input['columns'] ?? null, $table);

        $widths = [];

        if ($features->has(Feature::COLUMN_RESIZING) && is_array($input['widths'] ?? null)) {
            foreach ($input['widths'] as $key => $width) {
                if (is_string($key) && $table->columnFor($key) !== null && is_numeric($width)) {
                    // Only wide enough to stay grabbable: a narrow column is a
                    // legitimate choice, and its content is ellipsised, not fitted.
                    $widths[$key] = max(24, min(1200, (int) $width));
                }
            }
        }

        $group = null;

        if ($features->has(Feature::GROUPING) && is_string($input['group'] ?? null)) {
            $candidate = str_replace('.', '__', $input['group']);

            // Grouping by a computed accessor would mean ordering by something
            // that does not exist in SQL, so it is refused rather than faked.
            $groupColumn = $table->columnFor($candidate);
            $group = $groupColumn !== null && ! $groupColumn->isComputed() ? $candidate : null;
        }

        $trashed = 'without';

        if ($table->usesSoftDeletes() && in_array($input['trashed'] ?? null, ['with', 'only'], true)) {
            $trashed = $input['trashed'];
        }

        $state = new self(
            search: $search,
            columnSearch: $columnSearch,
            filters: $filters,
            rawFilters: $rawFilters,
            sort: $sort,
            page: max(1, (int) ($input['page'] ?? 1)),
            perPage: $perPage,
            columns: $columns,
            widths: $widths,
            group: $group,
            trashed: $trashed,
            selection: self::normalizeSelection($input['selection'] ?? null),
            view: isset($input['view']) && is_scalar($input['view']) ? (string) $input['view'] : null,
            params: self::normalizeParams($input['params'] ?? null, $table),
        );

        $state->warnings = $filterEngine->warnings();

        // The table reads its own parameters — $this->param('from_date') inside
        // query() — so the validated values are handed back to it here, where
        // every entry point (data, export, print, actions) passes through.
        $table->useParams($state->params);

        return $state;
    }

    /**
     * @return list<array{field: string, direction: string}>
     */
    private static function normalizeSort(mixed $input, DynamicTable $table): array
    {
        if (! $table->hasFeature(Feature::SORTING)) {
            return self::defaultSort($table);
        }

        $entries = [];

        if (is_string($input) && $input !== '') {
            $direction = str_starts_with($input, '-') ? 'desc' : 'asc';
            $entries[] = ['field' => ltrim($input, '-+'), 'direction' => $direction];
        } elseif (is_array($input)) {
            foreach (array_is_list($input) ? $input : [$input] as $entry) {
                if (is_string($entry)) {
                    $entries[] = [
                        'field' => ltrim($entry, '-+'),
                        'direction' => str_starts_with($entry, '-') ? 'desc' : 'asc',
                    ];
                } elseif (is_array($entry) && isset($entry['field'])) {
                    $entries[] = [
                        'field' => (string) $entry['field'],
                        'direction' => strtolower((string) ($entry['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
                    ];
                }
            }
        }

        $sort = [];

        foreach (array_slice($entries, 0, 3) as $entry) {
            $key = str_replace('.', '__', $entry['field']);
            $column = $table->columnFor($key);

            if ($column === null || ! $column->sortable) {
                continue;
            }

            $sort[] = ['field' => $key, 'direction' => $entry['direction']];
        }

        return $sort !== [] ? $sort : self::defaultSort($table);
    }

    /** @return list<array{field: string, direction: string}> */
    private static function defaultSort(DynamicTable $table): array
    {
        $resolved = $table->resolvedColumns();
        $sort = [];

        foreach ($table->defaultSort() as $path => $direction) {
            $key = str_replace('.', '__', (string) $path);

            if (isset($resolved[$key])) {
                $sort[] = ['field' => $key, 'direction' => strtolower($direction) === 'desc' ? 'desc' : 'asc'];
            }
        }

        return $sort;
    }

    /** @return list<string> */
    private static function normalizeColumns(mixed $input, DynamicTable $table): array
    {
        $resolved = $table->resolvedColumns();

        if ($table->hasFeature(Feature::COLUMN_PICKER) && is_array($input) && $input !== []) {
            $columns = [];

            foreach ($input as $key) {
                // columnFor() also accepts a column the table never declared —
                // the picker can add any field the metadata engine reaches —
                // and returns null for anything hidden, disallowed, too deep or
                // simply non-existent.
                if (is_string($key) && $table->columnFor($key) !== null && ! in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }

            if ($columns !== []) {
                return $columns;
            }
        }

        $columns = [];

        foreach ($resolved as $key => $column) {
            if ($column->visible) {
                $columns[] = $key;
            }
        }

        return $columns;
    }

    /**
     * Keep only the parameters the table declares, with values a query can
     * safely consume: a scalar, or a flat list of scalars.
     *
     * @return array<string, mixed>
     */
    private static function normalizeParams(mixed $input, DynamicTable $table): array
    {
        $declared = $table->declaredParams();

        if ($declared === []) {
            return [];
        }

        $input = is_array($input) ? $input : [];
        $params = [];

        foreach ($declared as $name => $default) {
            $value = self::normalizeParamValue($input[$name] ?? null);

            if ($value === null) {
                $value = self::normalizeParamValue($default);
            }

            if ($value !== null) {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    private static function normalizeParamValue(mixed $value): string|int|float|bool|array|null
    {
        if (is_array($value)) {
            $values = [];

            foreach (array_slice(array_values($value), 0, 200) as $entry) {
                $entry = self::normalizeParamValue($entry);

                if (! is_array($entry) && $entry !== null) {
                    $values[] = $entry;
                }
            }

            return $values !== [] ? $values : null;
        }

        if (is_string($value)) {
            $value = mb_substr(trim($value), 0, 500);

            return $value !== '' ? $value : null;
        }

        return is_scalar($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function normalizeSelection(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $mode = ($input['mode'] ?? 'include') === 'exclude' ? 'exclude' : 'include';
        $ids = [];

        foreach (array_slice((array) ($input['ids'] ?? []), 0, 5000) as $id) {
            if (is_scalar($id)) {
                $ids[] = is_numeric($id) ? (int) $id : (string) $id;
            }
        }

        return ['mode' => $mode, 'ids' => array_values(array_unique($ids))];
    }

    /**
     * Whether anything narrows the result set below the whole table.
     *
     * When nothing does, the table's own row estimate is also the estimate for
     * this result set — which is what lets an uncounted table still show a
     * meaningful "of about N".
     */
    public function isUnfiltered(): bool
    {
        return $this->search === ''
            && $this->columnSearch === []
            && $this->filters->isEmpty()
            && $this->params === []
            && $this->trashed === 'without';
    }

    public function hasSelection(): bool
    {
        return $this->selection !== [] && ($this->selection['mode'] === 'exclude' || $this->selection['ids'] !== []);
    }

    /** @return array<string, mixed> The client-facing state snapshot. */
    public function toArray(): array
    {
        return array_filter([
            'search' => $this->search,
            'columnSearch' => $this->columnSearch,
            'filters' => $this->filters->isEmpty() ? null : $this->filters->toArray(),
            'sort' => $this->sort,
            'page' => $this->page,
            'perPage' => $this->perPage,
            'columns' => $this->columns,
            'widths' => $this->widths,
            'group' => $this->group,
            'trashed' => $this->trashed === 'without' ? null : $this->trashed,
            'view' => $this->view,
            'params' => $this->params,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** The subset of state a saved view persists. */
    public function toViewConfiguration(): array
    {
        return array_filter([
            'version' => 1,
            'columns' => $this->columns,
            'widths' => $this->widths,
            'filters' => $this->filters->isEmpty() ? null : $this->filters->toArray(),
            'sort' => $this->sort,
            'search' => $this->search !== '' ? $this->search : null,
            'columnSearch' => $this->columnSearch,
            'group' => $this->group,
            'perPage' => $this->perPage,
            'trashed' => $this->trashed === 'without' ? null : $this->trashed,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }
}

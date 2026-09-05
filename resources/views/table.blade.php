@php
    /**
     * The single canonical DynamicTable template.
     *
     * Every theme shares this markup and differs only in the class map, so a
     * custom theme is one array rather than a folder of Blade files. The first
     * page of rows is rendered here so the table is readable before any
     * JavaScript executes; the core module then takes over in place.
     */
    $classes = $boot['classes'];
    $features = $boot['features'];
    $data = $boot['data'] ?? ['rows' => [], 'total' => 0, 'from' => 0, 'to' => 0, 'page' => 1, 'lastPage' => 1];
    $visible = collect($boot['columns'])->where('visible', true)->values();
    $sort = collect($boot['state']['sort'] ?? [])->keyBy('field');
    $selectable = $features['selection'] ?? false;
    $expandable = $features['row_detail'] ?? false;

    // The column a drag writes to, or null. Whether a drag is possible right
    // now also depends on the sort, and that is the reorder module's question:
    // the reader can change the sort without asking the server.
    $reorderable = $boot['reorderable'] ?? null;
    $pinnable = $features['pinned_rows'] ?? false;
    $sticky = array_flip($boot['sticky'] ?? []);
    $toolbarActions = collect($boot['toolbarActions'] ?? []);
    $leading = ($selectable ? 1 : 0) + ($expandable ? 1 : 0) + ($reorderable ? 1 : 0) + ($pinnable ? 1 : 0);
    $headerMenu = in_array('header-menu', $boot['modules'] ?? [], true);
    $summaries = $data['summaries'] ?? [];

    // Which columns a filter is about, at any depth of the condition tree. The
    // marker is derived from the tree rather than stored beside it, so the
    // builder, the header menu and a saved view all light up the same way.
    $filteredKeys = (function (array $node) use (&$filteredKeys): array {
        $keys = [];

        foreach ($node['conditions'] ?? [] as $child) {
            if (isset($child['conditions'])) {
                $keys = array_merge($keys, $filteredKeys($child));
            } elseif (! empty($child['field'])) {
                $keys[] = str_replace('.', '__', (string) $child['field']);
            }
        }

        return $keys;
    })($boot['state']['filters'] ?? []);

    $filtered = array_flip($filteredKeys);
    $t = fn (string $key, array $replace = []) => __('dynamic-table::table.'.$key, $replace);

    /*
     * The application's own markup, already rendered and already escaped where
     * escaping was the right thing — see Support\Slots. A theme template that
     * renders this file's markup itself gets an empty map rather than an
     * undefined variable.
     */
    $slots = $slots ?? [];
    $slot = fn (string $name) => $slots[$name] ?? null;
@endphp

{!! $assets->head() !!}

<div
    id="{{ $id }}"
    class="{{ $classes['root'] }} {{ $classes['wrapper'] ?? '' }}"
    dir="{{ $boot['direction'] }}"
    @if (! empty($boot['scheme'])) data-dynamic-table-scheme="{{ $boot['scheme'] }}" @endif
    data-dynamic-table
    data-table="{{ $boot['key'] }}"
    role="region"
    aria-label="{{ $boot['title'] }}"
>
    <script type="application/json" data-dynamic-table-boot>@json($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

    <div class="{{ $classes['toolbar'] }}" data-dynamic-table-toolbar>
        <div class="dynamic-table-toolbar-start">
            @if ($slot('toolbar.start'))
                <div class="dynamic-table-slot" data-dynamic-table-slot="toolbar.start">{!! $slot('toolbar.start') !!}</div>
            @endif

            @if ($features['search'] ?? false)
                <label class="dynamic-table-visually-hidden" for="{{ $id }}-search">{{ $t('search') }}</label>
                <input
                    id="{{ $id }}-search"
                    type="search"
                    class="{{ $classes['search'] ?? $classes['input'] ?? '' }}"
                    placeholder="{{ $t('search') }}"
                    value="{{ $boot['state']['search'] ?? '' }}"
                    data-dynamic-table-search
                    autocomplete="off"
                >
            @endif

            @if ($features['filters'] ?? false)
                <button type="button" class="{{ $classes['button'] ?? '' }}" data-dynamic-table-open="filters" aria-haspopup="dialog">
                    {{ $t('filters') }}
                    <span class="{{ $classes['chip'] ?? '' }} dynamic-table-hidden" data-dynamic-table-filter-count>0</span>
                </button>
            @endif

            @foreach ($toolbarActions->where('align', 'start') as $action)
                @include('dynamic-table::partials.toolbar-action', ['action' => $action, 'classes' => $classes])
            @endforeach

            <span class="dynamic-table-selection-summary dynamic-table-hidden" data-dynamic-table-selection-summary></span>
        </div>

        <div class="dynamic-table-toolbar-end">
            @if ($features['saved_views'] ?? false)
                {{-- The current view is the heading, as in Dynamics 365 views. --}}
                <button
                    type="button"
                    class="dynamic-table-view-picker"
                    data-dynamic-table-open="views"
                    aria-haspopup="menu"
                    aria-expanded="false"
                    title="{{ $t('views.title') }}"
                >
                    <span class="dynamic-table-view-name" data-dynamic-table-view-label>{{ $boot['viewName'] ?? $t('views.title') }}</span>
                    <span class="dynamic-table-caret" aria-hidden="true"></span>
                </button>
            @endif

            @if (($features['column_picker'] ?? false) || ($features['column_reorder'] ?? false))
                <button type="button" class="{{ $classes['button'] ?? '' }}" data-dynamic-table-open="columns" aria-haspopup="dialog">
                    {{ $t('columns') }}
                </button>
            @endif

            @if (($features['export'] ?? false) && ($boot['permissions']['export'] ?? false))
                <button type="button" class="{{ $classes['button'] ?? '' }}" data-dynamic-table-open="export">
                    {{ $t('export.title') }}
                </button>
            @endif

            @if (($features['print'] ?? false) && ($boot['permissions']['print'] ?? false))
                {{-- A link, not a fetch: printing is a page, and a page is a URL. --}}
                <a
                    class="{{ $classes['button'] ?? '' }}"
                    href="{{ $boot['endpoints']['print'] }}?table={{ $boot['key'] }}"
                    target="_blank"
                    data-dynamic-table-print
                >{{ $t('print.title') }}</a>
            @endif

            @if (($features['import'] ?? false) && ($boot['permissions']['import'] ?? false))
                <button type="button" class="{{ $classes['button'] ?? '' }}" data-dynamic-table-open="import">
                    {{ $t('import.title') }}
                </button>
            @endif

            @foreach ($toolbarActions->where('align', '!=', 'start') as $action)
                @include('dynamic-table::partials.toolbar-action', ['action' => $action, 'classes' => $classes])
            @endforeach

            @if (($features['inline_create'] ?? false) && ($boot['permissions']['create'] ?? false))
                <button type="button" class="{{ $classes['buttonPrimary'] ?? '' }}" data-dynamic-table-create>
                    {{ $t('create.title') }}
                </button>
            @endif

            @if (($boot['actions'] ?? []) || ($features['bulk_edit'] ?? false))
                <div class="dynamic-table-actions dynamic-table-hidden" data-dynamic-table-actions>
                    @if ($features['bulk_edit'] ?? false)
                        <button type="button" class="{{ $classes['button'] ?? '' }}" data-dynamic-table-open="bulk-edit">
                            {{ $t('bulk_edit.title') }}
                        </button>
                    @endif

                    @if ($boot['actions'] ?? [])
                        <button type="button" class="{{ $classes['buttonPrimary'] ?? '' }}" data-dynamic-table-open="actions">
                            {{ $t('actions.title') }}
                        </button>
                    @endif
                </div>
            @endif

            @if ($slot('toolbar.end'))
                <div class="dynamic-table-slot" data-dynamic-table-slot="toolbar.end">{!! $slot('toolbar.end') !!}</div>
            @endif
        </div>
    </div>

    <div class="dynamic-table-alerts" data-dynamic-table-alerts aria-live="polite"></div>

    @if ($slot('above'))
        <div class="dynamic-table-slot dynamic-table-slot-above" data-dynamic-table-slot="above">{!! $slot('above') !!}</div>
    @endif

    {{-- The height lives on the element, because a sticky header needs a box that scrolls. --}}
    <div
        class="{{ $classes['scroller'] }}"
        data-dynamic-table-scroller
        @if (! empty($boot['maxHeight'])) style="--dynamic-table-max-height: {{ $boot['maxHeight'] }}" @endif
    >
        @php
            /*
             * Sized columns switch the table to fixed layout; see .dynamic-table-sized.
             *
             * A width declared on the column counts, not only one the reader
             * dragged: auto layout treats a width as a suggestion and refuses
             * to go under the header's own min-content width, which is how a
             * column asked for 25px comes out at 180.
             */
            $sizes = collect($visible)
                ->mapWithKeys(fn ($column) => [
                    $column['key'] => $state->widths[$column['key']] ?? ($column['width'] ?? null),
                ])
                ->filter()
                ->all();

            $sized = $sizes !== [];

            /*
             * A column narrowed to the width of "$2" cannot also carry a
             * cell's worth of padding: border-box means the padding would eat
             * the whole column and leave no room even for the ellipsis. The
             * same threshold as syncSizedLayout() in core.js.
             */
            $narrow = array_filter($sizes, fn ($width) => $width < 64);
        @endphp

        <table class="{{ $classes['table'] }} @if ($sized) dynamic-table-sized @endif" data-dynamic-table-table>
            <thead class="{{ $classes['thead'] }}">
                <tr class="{{ $classes['headRow'] }}">
                    @if ($pinnable)
                        <th class="{{ $classes['th'] }} dynamic-table-pin-cell" scope="col">
                            <span class="dynamic-table-visually-hidden">{{ $t('pin_row') }}</span>
                        </th>
                    @endif

                    @if ($reorderable)
                        <th class="{{ $classes['th'] }} dynamic-table-reorder-cell" scope="col">
                            <span class="dynamic-table-visually-hidden">{{ $t('reorder_row') }}</span>
                        </th>
                    @endif

                    @if ($expandable)
                        <th class="{{ $classes['th'] }} dynamic-table-expand-cell" scope="col">
                            <span class="dynamic-table-visually-hidden">{{ $t('detail.title') }}</span>
                        </th>
                    @endif

                    @if ($selectable)
                        <th class="{{ $classes['th'] }} dynamic-table-select-cell" scope="col">
                            <input type="checkbox" data-dynamic-table-select-all aria-label="{{ $t('select_all') }}">
                        </th>
                    @endif

                    @foreach ($visible as $column)
                        @php
                            $current = $sort->get($column['key']);
                            $width = $sizes[$column['key']] ?? null;
                        @endphp
                        <th
                            class="{{ $classes['th'] }} @if(!empty($column['sortable']) && ! $headerMenu) {{ $classes['thSortable'] }} @endif dynamic-table-align-{{ $column['align'] ?? 'start' }} @if (isset($narrow[$column['key']])) dynamic-table-narrow @endif"
                            scope="col"
                            data-dynamic-table-column="{{ $column['key'] }}"
                            @if (isset($sticky[$column['key']])) data-dynamic-table-sticky @endif
                            @if (isset($filtered[$column['key']])) data-dynamic-table-filtered @endif
                            @if (! empty($width)) style="width: {{ $width }}px" @endif
                            @if (!empty($column['sortable'])) aria-sort="{{ $current ? ($current['direction'] === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif
                        >
                            @if ($headerMenu)
                                {{--
                                    With the header menu on, the header opens the
                                    menu instead of sorting: Dynamics puts both
                                    sort directions in that menu, and a header
                                    that both sorts and opens a menu makes one of
                                    the two an accident.
                                --}}
                                <button
                                    type="button"
                                    class="dynamic-table-header-trigger"
                                    data-dynamic-table-header-menu="{{ $column['key'] }}"
                                    aria-haspopup="menu"
                                    aria-expanded="false"
                                    aria-label="{{ $t('header.menu', ['column' => $column['label']]) }}"
                                >
                                    <span>{{ $column['label'] }}</span>
                                    <span class="dynamic-table-sort-icon" aria-hidden="true">{{ $current ? ($current['direction'] === 'asc' ? '▲' : '▼') : '' }}</span>
                                    @if (isset($filtered[$column['key']]))
                                        <span class="dynamic-table-filtered-icon" aria-hidden="true">▼</span>
                                    @endif
                                    <span class="dynamic-table-header-cog" aria-hidden="true">⚙</span>
                                </button>
                            @elseif (!empty($column['sortable']))
                                <button type="button" class="dynamic-table-sort" data-dynamic-table-sort="{{ $column['key'] }}">
                                    <span>{{ $column['label'] }}</span>
                                    <span class="dynamic-table-sort-icon" aria-hidden="true">{{ $current ? ($current['direction'] === 'asc' ? '▲' : '▼') : '' }}</span>
                                </button>
                            @else
                                <span>{{ $column['label'] }}</span>
                            @endif

                            @if ($features['column_resize'] ?? false)
                                <span class="{{ $classes['resizer'] }}" data-dynamic-table-resizer="{{ $column['key'] }}" role="separator" aria-orientation="vertical"></span>
                            @endif
                        </th>
                    @endforeach

                    @if ($boot['rowActions'] ?? [])
                        <th class="{{ $classes['th'] }} dynamic-table-row-actions-cell" scope="col">
                            <span class="dynamic-table-visually-hidden">{{ $t('actions.title') }}</span>
                        </th>
                    @endif
                </tr>

                @if ($features['column_search'] ?? false)
                    {{--
                        One cell per cell of the row above it, expander and row
                        buttons included. A search row that skips them is a
                        search row whose inputs sit under the wrong columns.
                    --}}
                    <tr class="{{ $classes['filterRow'] }}" data-dynamic-table-search-row>
                        @if ($pinnable)<th class="{{ $classes['th'] }} dynamic-table-pin-cell"></th>@endif
                        @if ($reorderable)<th class="{{ $classes['th'] }} dynamic-table-reorder-cell"></th>@endif
                        @if ($expandable)<th class="{{ $classes['th'] }} dynamic-table-expand-cell"></th>@endif
                        @if ($selectable)<th class="{{ $classes['th'] }} dynamic-table-select-cell"></th>@endif
                        @foreach ($visible as $column)
                            <th class="{{ $classes['th'] }} @if (isset($narrow[$column['key']])) dynamic-table-narrow @endif" data-dynamic-table-search-cell="{{ $column['key'] }}">
                                @if (!empty($column['filterable']))
                                    <input
                                        type="text"
                                        class="{{ $classes['input'] ?? '' }}"
                                        data-dynamic-table-column-search="{{ $column['key'] }}"
                                        value="{{ $boot['state']['columnSearch'][$column['key']] ?? '' }}"
                                        aria-label="{{ $t('search_column', ['column' => $column['label']]) }}"
                                    >
                                @endif
                            </th>
                        @endforeach

                        @if ($boot['rowActions'] ?? [])
                            <th class="{{ $classes['th'] }} dynamic-table-row-actions-cell"></th>
                        @endif
                    </tr>
                @endif
            </thead>

            <tbody class="{{ $classes['tbody'] }}" data-dynamic-table-body>
                @php
                    /*
                     * Grouping, drawn here as well as in renderRows().
                     *
                     * A remembered state, a URL and a saved view can all arrive
                     * with a group already chosen, so the first paint has to
                     * show the same headers the next fetch will — otherwise the
                     * groups appear only once something else causes a refresh.
                     *
                     * The query is already ordered by the group column, so a
                     * change of value is all that starts a new group. The
                     * sentinel is an object, so a genuinely null first value
                     * still opens one.
                     */
                    $groupKey = ($features['grouping'] ?? false) ? ($boot['state']['group'] ?? null) : null;
                    $lastGroup = new stdClass;
                    $span = count($visible) + $leading + (($boot['rowActions'] ?? []) ? 1 : 0);

                    $groupLabel = $groupKey === null
                        ? null
                        : (collect($boot['columns'])->firstWhere('key', $groupKey)['label'] ?? $groupKey);

                    /*
                     * Subtotals per heading, keyed by the formatted value the
                     * row carries — the same key the JS renderer builds, from
                     * the same function, so a group's totals do not move when
                     * the second page arrives.
                     */
                    $groupTotals = $data['groupSummaries'] ?? [];
                    $totalsFor = fn ($value) => $groupTotals[\Shwaeki\DynamicTable\Query\QueryEngine::groupKey($value)] ?? [];
                @endphp

                @forelse ($data['rows'] as $row)
                    @if ($groupKey)
                        @php $groupValue = $row['c'][$groupKey] ?? null; @endphp

                        @if ($groupValue !== $lastGroup)
                            @php $lastGroup = $groupValue; @endphp
                            <tr class="{{ $classes['group'] ?? '' }} dynamic-table-group-row">
                                <td colspan="{{ $span }}">
                                    <span class="dynamic-table-group-label">{{ $groupLabel }}: </span>
                                    <strong>{{ $groupValue === null || $groupValue === '' ? '—' : $groupValue }}</strong>

                                    {{-- Mirrored by renderGroupRow(). --}}
                                    @php $totals = $totalsFor($groupValue); @endphp

                                    @if ($totals !== [])
                                        <span class="dynamic-table-group-summaries">
                                            @foreach ($visible as $column)
                                                @if (isset($totals[$column['key']]))
                                                    <span class="dynamic-table-group-summary">
                                                        <span class="dynamic-table-summary-label">{{ $column['label'] }} · {{ $t('summary.'.$column['summary']) }}</span>
                                                        <span class="dynamic-table-summary-value">{{ $totals[$column['key']] }}</span>
                                                    </span>
                                                @endif
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endif

                    {{-- data-trashed mirrors renderRow(); without it a soft-deleted
                         row was struck through only after the first repaint. --}}
                    <tr
                        class="{{ $classes['row'] }}"
                        data-dynamic-table-row="{{ $row['id'] }}"
                        @if (! empty($row['trashed'])) data-trashed @endif
                    >
                        @if ($pinnable)
                            {{-- Mirrored by renderRow(); the pins module fills the star it holds. --}}
                            <td class="{{ $classes['cell'] }} dynamic-table-pin-cell">
                                <button type="button" class="dynamic-table-pin" data-dynamic-table-pin="{{ $row['id'] }}" aria-pressed="false" title="{{ $t('pin_row') }}">&#9734;</button>
                            </td>
                        @endif

                        @if ($reorderable)
                            <td class="{{ $classes['cell'] }} dynamic-table-reorder-cell">
                                {{-- Mirrored by renderRow(); hidden until the reorder module says the sort allows a drag. --}}
                                <button type="button" class="dynamic-table-reorder-handle" data-dynamic-table-reorder-handle aria-label="{{ $t('reorder_row') }}" title="{{ $t('reorder_row') }}" hidden>&equiv;</button>
                            </td>
                        @endif
                        @if ($expandable)
                            <td class="{{ $classes['cell'] }} dynamic-table-expand-cell">
                                <button
                                    type="button"
                                    class="dynamic-table-expand"
                                    data-dynamic-table-detail="{{ $row['id'] }}"
                                    aria-expanded="false"
                                    aria-label="{{ $t('detail.toggle') }}"
                                >›</button>
                            </td>
                        @endif

                        @if ($selectable)
                            <td class="{{ $classes['cell'] }} dynamic-table-select-cell">
                                <input type="checkbox" data-dynamic-table-select="{{ $row['id'] }}" aria-label="{{ $t('select_row') }}">
                            </td>
                        @endif

                        @foreach ($visible as $column)
                            @php $value = $row['c'][$column['key']] ?? null; @endphp
                            <td
                                class="{{ $classes['cell'] }} dynamic-table-align-{{ $column['align'] ?? 'start' }} @if (isset($narrow[$column['key']])) dynamic-table-narrow @endif {{ $column['class'] ?? '' }}"
                                data-dynamic-table-cell="{{ $column['key'] }}"
                                @if (isset($sticky[$column['key']])) data-dynamic-table-sticky @endif
                                data-label="{{ $column['label'] }}"
                                @if (!empty($column['editable']) && ($features['inline_edit'] ?? false)) data-dynamic-table-editable @endif
                            >
                                @if (! empty($row['u']) && $loop->first)
                                    {{-- A real link in the first cell; mirrored by linkCell(). The rest
                                         of the row is the click handler's job, and only a convenience
                                         on top of this one. --}}
                                    <a class="dynamic-table-row-link" href="{{ $row['u'] }}">
                                        @include('dynamic-table::partials.cell', ['column' => $column, 'value' => $value, 'classes' => $classes, 'html' => isset($row['h'][$column['key']])])
                                    </a>
                                @else
                                    @include('dynamic-table::partials.cell', ['column' => $column, 'value' => $value, 'classes' => $classes, 'html' => isset($row['h'][$column['key']])])
                                @endif
                            </td>
                        @endforeach

                        @if ($boot['rowActions'] ?? [])
                            <td class="{{ $classes['cell'] }} dynamic-table-row-actions-cell">
                                @foreach ($boot['rowActions'] as $action)
                                    @php $applies = $row['a'][$action['name']] ?? null; @endphp

                                    @if ($applies === null)
                                        @continue
                                    @endif

                                    @php
                                        /* Mirrors the browser's renderRow(): icon, label, or both. */
                                        $actionClass = trim('dynamic-table-row-action '
                                            .(! empty($action['destructive']) ? 'dynamic-table-row-action-danger ' : '')
                                            .(! empty($action['class']) ? 'dynamic-table-row-action-custom '.$action['class'] : ''));
                                    @endphp

                                    @if (! empty($action['link']))
                                        <a
                                            href="{{ $applies }}"
                                            class="{{ $actionClass }}"
                                            @if (!empty($action['target'])) target="{{ $action['target'] }}" rel="noopener" @endif
                                            title="{{ $action['label'] }}"
                                        >@include('dynamic-table::partials.row-action', ['action' => $action])</a>
                                    @else
                                        <button
                                            type="button"
                                            class="{{ $actionClass }}"
                                            data-dynamic-table-row-action="{{ $action['name'] }}"
                                            title="{{ $action['label'] }}"
                                        >@include('dynamic-table::partials.row-action', ['action' => $action])</button>
                                    @endif
                                @endforeach
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td class="{{ $classes['empty'] }}" colspan="{{ $span }}">
                            {{-- Mirrored by the JS renderer; see renderEmpty(). --}}
                            <div class="dynamic-table-empty-state" data-dynamic-table-empty>
                                <p class="dynamic-table-empty-title">
                                    {{ ($data['emptyReason'] ?? 'none') === 'filtered' ? $t('empty_filtered') : $t('empty') }}
                                </p>

                                @if (($data['emptyReason'] ?? null) === 'filtered')
                                    <p class="dynamic-table-empty-hint">{{ $t('empty_filtered_hint') }}</p>

                                    <button type="button" class="{{ $classes['button'] ?? '' }}" data-dynamic-table-clear-filters>
                                        {{ $t('clear_filters') }}
                                    </button>
                                @elseif ($slot('empty'))
                                    {{--
                                        Only when the table is genuinely empty.
                                        "Add the first product" under a filter
                                        that matched nothing answers a question
                                        the reader did not ask — they have
                                        products, just not these.
                                    --}}
                                    <div class="dynamic-table-slot" data-dynamic-table-slot="empty">{!! $slot('empty') !!}</div>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>

            @if ($summaries !== [])
                {{--
                    The summary row.

                    In <tfoot>, which is where a table's totals belong: screen
                    readers announce it as such, and it prints on every page.
                --}}
                <tfoot class="dynamic-table-tfoot" data-dynamic-table-summary-row>
                    <tr class="dynamic-table-summary-row">
                        @if ($pinnable)<td class="{{ $classes['cell'] }}"></td>@endif
                        @if ($reorderable)<td class="{{ $classes['cell'] }}"></td>@endif
                        @if ($expandable)<td class="{{ $classes['cell'] }}"></td>@endif
                        @if ($selectable)<td class="{{ $classes['cell'] }}"></td>@endif

                        @foreach ($visible as $column)
                            <td
                                class="{{ $classes['cell'] }} dynamic-table-align-{{ $column['align'] ?? 'start' }}"
                                data-dynamic-table-summary="{{ $column['key'] }}"
                            >
                                @if (isset($summaries[$column['key']]))
                                    <span class="dynamic-table-summary-label">{{ $t('summary.'.$column['summary']) }}</span>
                                    <span class="dynamic-table-summary-value">{{ $summaries[$column['key']] }}</span>
                                @endif
                            </td>
                        @endforeach

                        @if ($boot['rowActions'] ?? [])<td class="{{ $classes['cell'] }}"></td>@endif
                    </tr>
                </tfoot>
            @endif
        </table>

        @if (($boot['paginationStyle'] ?? 'pages') === 'infinite')
            {{-- Watched by the core module; crossing it fetches the next page. --}}
            <div class="dynamic-table-sentinel" data-dynamic-table-sentinel aria-hidden="true"></div>
        @endif

        <div class="{{ $classes['loading'] }}" data-dynamic-table-loading hidden>
            <span class="dynamic-table-spinner" aria-hidden="true"></span>
            <span>{{ $t('loading') }}</span>
        </div>
    </div>

    @if ($features['pagination'] ?? false)
        <div class="{{ $classes['footer'] }}">
            {{--
                "range", not "summary": the summary-row cells below the table
                carry data-dynamic-table-summary, and an attribute selector
                matches on the name alone. Sharing it meant querySelector()
                found the first tfoot cell instead of this line, and the page
                range was written over a column's total on every refresh.
            --}}
            <div class="dynamic-table-summary" data-dynamic-table-range>
                @php
                    $n = fn ($v) => number_format((int) $v);
                    $range = ['from' => $n($data['from'] ?? 0), 'to' => $n($data['to'] ?? 0)];
                @endphp
                @if (($data['counted'] ?? true) !== false)
                    {{ $t('showing', $range + ['total' => $n($data['total'] ?? 0)]) }}
                @elseif (! empty($data['estimate']))
                    {{ $t('showing_estimated', $range + ['total' => $n($data['estimate'])]) }}
                @else
                    {{ $t('showing_uncounted', $range) }}
                @endif
            </div>

            <div class="dynamic-table-pagination-controls" @if (($boot['paginationStyle'] ?? 'pages') === 'infinite') data-dynamic-table-infinite @endif>
                <label class="dynamic-table-per-page">
                    <span class="dynamic-table-visually-hidden">{{ $t('per_page') }}</span>
                    <select class="{{ $classes['select'] ?? '' }}" data-dynamic-table-per-page>
                        @foreach ($boot['perPageOptions'] as $option)
                            <option value="{{ $option }}" @selected($option === ($boot['state']['perPage'] ?? 0))>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>

                <nav class="{{ $classes['pagination'] }}" data-dynamic-table-pagination aria-label="{{ $t('pagination') }}"></nav>
            </div>
        </div>
    @endif

    @if ($slot('below'))
        <div class="dynamic-table-slot dynamic-table-slot-below" data-dynamic-table-slot="below">{!! $slot('below') !!}</div>
    @endif

    @if ($boot['panel'] ?? false)
        <div class="{{ $classes['panel'] }}" data-dynamic-table-panel></div>
    @endif
</div>

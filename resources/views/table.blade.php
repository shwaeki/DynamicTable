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
    $sticky = array_flip($boot['sticky'] ?? []);
    $toolbarActions = collect($boot['toolbarActions'] ?? []);
    $leading = ($selectable ? 1 : 0) + ($expandable ? 1 : 0);
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
@endphp

{!! $assets->head() !!}

<div
    id="{{ $id }}"
    class="{{ $classes['root'] }} {{ $classes['wrapper'] ?? '' }}"
    dir="{{ $boot['direction'] }}"
    @if (! empty($boot['scheme'])) data-dt-scheme="{{ $boot['scheme'] }}" @endif
    data-dynamic-table
    data-table="{{ $boot['key'] }}"
    role="region"
    aria-label="{{ $boot['title'] }}"
>
    <script type="application/json" data-dt-boot>@json($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

    <div class="{{ $classes['toolbar'] }}" data-dt-toolbar>
        <div class="dt-toolbar-start">
            @if ($features['search'] ?? false)
                <label class="dt-visually-hidden" for="{{ $id }}-search">{{ $t('search') }}</label>
                <input
                    id="{{ $id }}-search"
                    type="search"
                    class="{{ $classes['search'] ?? $classes['input'] ?? '' }}"
                    placeholder="{{ $t('search') }}"
                    value="{{ $boot['state']['search'] ?? '' }}"
                    data-dt-search
                    autocomplete="off"
                >
            @endif

            @if ($features['filters'] ?? false)
                <button type="button" class="{{ $classes['button'] ?? '' }}" data-dt-open="filters" aria-haspopup="dialog">
                    {{ $t('filters') }}
                    <span class="{{ $classes['chip'] ?? '' }} dt-hidden" data-dt-filter-count>0</span>
                </button>
            @endif

            @foreach ($toolbarActions->where('align', 'start') as $action)
                @include('dynamic-table::partials.toolbar-action', ['action' => $action, 'classes' => $classes])
            @endforeach

            <span class="dt-selection-summary dt-hidden" data-dt-selection-summary></span>
        </div>

        <div class="dt-toolbar-end">
            @if ($features['views'] ?? false)
                {{-- The current view is the heading, as in Dynamics 365 views. --}}
                <button
                    type="button"
                    class="dt-view-picker"
                    data-dt-open="views"
                    aria-haspopup="menu"
                    aria-expanded="false"
                    title="{{ $t('views.title') }}"
                >
                    <span class="dt-view-name" data-dt-view-label>{{ $boot['viewName'] ?? $t('views.title') }}</span>
                    <span class="dt-caret" aria-hidden="true"></span>
                </button>
            @endif

            @if ($features['column_picker'] ?? false)
                <button type="button" class="{{ $classes['button'] ?? '' }}" data-dt-open="columns" aria-haspopup="dialog">
                    {{ $t('columns') }}
                </button>
            @endif

            @if (($features['export'] ?? false) && ($boot['permissions']['export'] ?? false))
                <button type="button" class="{{ $classes['button'] ?? '' }}" data-dt-open="export">
                    {{ $t('export.title') }}
                </button>
            @endif

            @if (($features['print'] ?? false) && ($boot['permissions']['print'] ?? false))
                {{-- A link, not a fetch: printing is a page, and a page is a URL. --}}
                <a
                    class="{{ $classes['button'] ?? '' }}"
                    href="{{ $boot['endpoints']['print'] }}?table={{ $boot['key'] }}"
                    target="_blank"
                    data-dt-print
                >{{ $t('print.title') }}</a>
            @endif

            @if (($features['import'] ?? false) && ($boot['permissions']['import'] ?? false))
                <button type="button" class="{{ $classes['button'] ?? '' }}" data-dt-open="import">
                    {{ $t('import.title') }}
                </button>
            @endif

            @foreach ($toolbarActions->where('align', '!=', 'start') as $action)
                @include('dynamic-table::partials.toolbar-action', ['action' => $action, 'classes' => $classes])
            @endforeach

            @if (($features['create'] ?? false) && ($boot['permissions']['create'] ?? false))
                <button type="button" class="{{ $classes['buttonPrimary'] ?? '' }}" data-dt-create>
                    {{ $t('create.title') }}
                </button>
            @endif

            @if (($boot['actions'] ?? []) || ($features['bulk_edit'] ?? false))
                <div class="dt-actions dt-hidden" data-dt-actions>
                    @if ($features['bulk_edit'] ?? false)
                        <button type="button" class="{{ $classes['button'] ?? '' }}" data-dt-open="bulk-edit">
                            {{ $t('bulk_edit.title') }}
                        </button>
                    @endif

                    @if ($boot['actions'] ?? [])
                        <button type="button" class="{{ $classes['buttonPrimary'] ?? '' }}" data-dt-open="actions">
                            {{ $t('actions.title') }}
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="dt-alerts" data-dt-alerts aria-live="polite"></div>

    {{-- The height lives on the element, because a sticky header needs a box that scrolls. --}}
    <div
        class="{{ $classes['scroller'] }}"
        data-dt-scroller
        @if (! empty($boot['maxHeight'])) style="--dt-max-height: {{ $boot['maxHeight'] }}" @endif
    >
        @php
            /*
             * Sized columns switch the table to fixed layout; see .dt-sized.
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

        <table class="{{ $classes['table'] }} @if ($sized) dt-sized @endif" data-dt-table>
            <thead class="{{ $classes['thead'] }}">
                <tr class="{{ $classes['headRow'] }}">
                    @if ($expandable)
                        <th class="{{ $classes['th'] }} dt-expand-cell" scope="col">
                            <span class="dt-visually-hidden">{{ $t('detail.title') }}</span>
                        </th>
                    @endif

                    @if ($selectable)
                        <th class="{{ $classes['th'] }} dt-select-cell" scope="col">
                            <input type="checkbox" data-dt-select-all aria-label="{{ $t('select_all') }}">
                        </th>
                    @endif

                    @foreach ($visible as $column)
                        @php
                            $current = $sort->get($column['key']);
                            $width = $sizes[$column['key']] ?? null;
                        @endphp
                        <th
                            class="{{ $classes['th'] }} @if(!empty($column['sortable']) && ! $headerMenu) {{ $classes['thSortable'] }} @endif dt-align-{{ $column['align'] ?? 'start' }} @if (isset($narrow[$column['key']])) dt-narrow @endif"
                            scope="col"
                            data-dt-column="{{ $column['key'] }}"
                            @if (isset($sticky[$column['key']])) data-dt-sticky @endif
                            @if (isset($filtered[$column['key']])) data-dt-filtered @endif
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
                                    class="dt-header-trigger"
                                    data-dt-header-menu="{{ $column['key'] }}"
                                    aria-haspopup="menu"
                                    aria-expanded="false"
                                    aria-label="{{ $t('header.menu', ['column' => $column['label']]) }}"
                                >
                                    <span>{{ $column['label'] }}</span>
                                    <span class="dt-sort-icon" aria-hidden="true">{{ $current ? ($current['direction'] === 'asc' ? '▲' : '▼') : '' }}</span>
                                    @if (isset($filtered[$column['key']]))
                                        <span class="dt-filtered-icon" aria-hidden="true">▼</span>
                                    @endif
                                    <span class="dt-header-cog" aria-hidden="true">⚙</span>
                                </button>
                            @elseif (!empty($column['sortable']))
                                <button type="button" class="dt-sort" data-dt-sort="{{ $column['key'] }}">
                                    <span>{{ $column['label'] }}</span>
                                    <span class="dt-sort-icon" aria-hidden="true">{{ $current ? ($current['direction'] === 'asc' ? '▲' : '▼') : '' }}</span>
                                </button>
                            @else
                                <span>{{ $column['label'] }}</span>
                            @endif

                            @if ($features['column_resizing'] ?? false)
                                <span class="{{ $classes['resizer'] }}" data-dt-resizer="{{ $column['key'] }}" role="separator" aria-orientation="vertical"></span>
                            @endif
                        </th>
                    @endforeach

                    @if ($boot['rowActions'] ?? [])
                        <th class="{{ $classes['th'] }} dt-row-actions-cell" scope="col">
                            <span class="dt-visually-hidden">{{ $t('actions.title') }}</span>
                        </th>
                    @endif
                </tr>

                @if ($features['column_search'] ?? false)
                    {{--
                        One cell per cell of the row above it, expander and row
                        buttons included. A search row that skips them is a
                        search row whose inputs sit under the wrong columns.
                    --}}
                    <tr class="{{ $classes['filterRow'] }}" data-dt-search-row>
                        @if ($expandable)<th class="{{ $classes['th'] }} dt-expand-cell"></th>@endif
                        @if ($selectable)<th class="{{ $classes['th'] }} dt-select-cell"></th>@endif
                        @foreach ($visible as $column)
                            <th class="{{ $classes['th'] }} @if (isset($narrow[$column['key']])) dt-narrow @endif" data-dt-search-cell="{{ $column['key'] }}">
                                @if (!empty($column['filterable']))
                                    <input
                                        type="text"
                                        class="{{ $classes['input'] ?? '' }}"
                                        data-dt-column-search="{{ $column['key'] }}"
                                        value="{{ $boot['state']['columnSearch'][$column['key']] ?? '' }}"
                                        aria-label="{{ $t('search_column', ['column' => $column['label']]) }}"
                                    >
                                @endif
                            </th>
                        @endforeach

                        @if ($boot['rowActions'] ?? [])
                            <th class="{{ $classes['th'] }} dt-row-actions-cell"></th>
                        @endif
                    </tr>
                @endif
            </thead>

            <tbody class="{{ $classes['tbody'] }}" data-dt-body>
                @forelse ($data['rows'] as $row)
                    <tr class="{{ $classes['row'] }}" data-dt-row="{{ $row['id'] }}">
                        @if ($expandable)
                            <td class="{{ $classes['cell'] }} dt-expand-cell">
                                <button
                                    type="button"
                                    class="dt-expand"
                                    data-dt-detail="{{ $row['id'] }}"
                                    aria-expanded="false"
                                    aria-label="{{ $t('detail.toggle') }}"
                                >›</button>
                            </td>
                        @endif

                        @if ($selectable)
                            <td class="{{ $classes['cell'] }} dt-select-cell">
                                <input type="checkbox" data-dt-select="{{ $row['id'] }}" aria-label="{{ $t('select_row') }}">
                            </td>
                        @endif

                        @foreach ($visible as $column)
                            @php $value = $row['c'][$column['key']] ?? null; @endphp
                            <td
                                class="{{ $classes['cell'] }} dt-align-{{ $column['align'] ?? 'start' }} @if (isset($narrow[$column['key']])) dt-narrow @endif {{ $column['class'] ?? '' }}"
                                data-dt-cell="{{ $column['key'] }}"
                                @if (isset($sticky[$column['key']])) data-dt-sticky @endif
                                data-label="{{ $column['label'] }}"
                                @if (!empty($column['editable']) && ($features['inline_edit'] ?? false)) data-dt-editable @endif
                            >
                                @include('dynamic-table::partials.cell', ['column' => $column, 'value' => $value, 'classes' => $classes, 'html' => isset($row['h'][$column['key']])])
                            </td>
                        @endforeach

                        @if ($boot['rowActions'] ?? [])
                            <td class="{{ $classes['cell'] }} dt-row-actions-cell">
                                @foreach ($boot['rowActions'] as $action)
                                    @php $applies = $row['a'][$action['name']] ?? null; @endphp

                                    @if ($applies === null)
                                        @continue
                                    @endif

                                    @if (! empty($action['link']))
                                        <a
                                            href="{{ $applies }}"
                                            class="dt-row-action {{ !empty($action['destructive']) ? 'dt-row-action-danger' : '' }}"
                                            @if (!empty($action['target'])) target="{{ $action['target'] }}" rel="noopener" @endif
                                            title="{{ $action['label'] }}"
                                        >{!! \Shwaeki\DynamicTable\Support\Icon::html($action['icon'] ?? $action['label']) !!}</a>
                                    @else
                                        <button
                                            type="button"
                                            class="dt-row-action {{ !empty($action['destructive']) ? 'dt-row-action-danger' : '' }}"
                                            data-dt-row-action="{{ $action['name'] }}"
                                            title="{{ $action['label'] }}"
                                        >{!! \Shwaeki\DynamicTable\Support\Icon::html($action['icon'] ?? $action['label']) !!}</button>
                                    @endif
                                @endforeach
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td class="{{ $classes['empty'] }}" colspan="{{ count($visible) + $leading + (($boot['rowActions'] ?? []) ? 1 : 0) }}">
                            {{-- Mirrored by the JS renderer; see renderEmpty(). --}}
                            <div class="dt-empty-state" data-dt-empty>
                                <p class="dt-empty-title">
                                    {{ ($data['emptyReason'] ?? 'none') === 'filtered' ? $t('empty_filtered') : $t('empty') }}
                                </p>

                                @if (($data['emptyReason'] ?? null) === 'filtered')
                                    <p class="dt-empty-hint">{{ $t('empty_filtered_hint') }}</p>

                                    <button type="button" class="{{ $classes['button'] ?? '' }}" data-dt-clear-filters>
                                        {{ $t('clear_filters') }}
                                    </button>
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
                <tfoot class="dt-tfoot" data-dt-summary-row>
                    <tr class="dt-summary-row">
                        @if ($expandable)<td class="{{ $classes['cell'] }}"></td>@endif
                        @if ($selectable)<td class="{{ $classes['cell'] }}"></td>@endif

                        @foreach ($visible as $column)
                            <td
                                class="{{ $classes['cell'] }} dt-align-{{ $column['align'] ?? 'start' }}"
                                data-dt-summary="{{ $column['key'] }}"
                            >
                                @if (isset($summaries[$column['key']]))
                                    <span class="dt-summary-label">{{ $t('summary.'.$column['summary']) }}</span>
                                    <span class="dt-summary-value">{{ $summaries[$column['key']] }}</span>
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
            <div class="dt-sentinel" data-dt-sentinel aria-hidden="true"></div>
        @endif

        <div class="{{ $classes['loading'] }}" data-dt-loading hidden>
            <span class="dt-spinner" aria-hidden="true"></span>
            <span>{{ $t('loading') }}</span>
        </div>
    </div>

    @if ($features['pagination'] ?? false)
        <div class="{{ $classes['footer'] }}">
            <div class="dt-summary" data-dt-summary>
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

            <div class="dt-pagination-controls" @if (($boot['paginationStyle'] ?? 'pages') === 'infinite') data-dt-infinite @endif>
                <label class="dt-per-page">
                    <span class="dt-visually-hidden">{{ $t('per_page') }}</span>
                    <select class="{{ $classes['select'] ?? '' }}" data-dt-per-page>
                        @foreach ($boot['perPageOptions'] as $option)
                            <option value="{{ $option }}" @selected($option === ($boot['state']['perPage'] ?? 0))>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>

                <nav class="{{ $classes['pagination'] }}" data-dt-pagination aria-label="{{ $t('pagination') }}"></nav>
            </div>
        </div>
    @endif

    @if ($boot['panel'] ?? false)
        <div class="{{ $classes['panel'] }}" data-dt-panel></div>
    @endif
</div>

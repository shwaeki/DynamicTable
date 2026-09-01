@php
    /** Server-side cell rendering; the JS renderer mirrors this exactly. */
    $type = $column['type'] ?? 'string';
    $format = $column['format'] ?? null;
    /* $html: this row's render closure returned an Htmlable for this cell. */
    $raw = ($column['raw'] ?? false) || $format === 'raw' || ($html ?? false);
@endphp

@if ($value === null || $value === '')
    <span class="dynamic-table-null" aria-hidden="true">—</span>
@elseif ($raw)
    {!! $value !!}
@elseif ($type === 'boolean')
    <span class="dynamic-table-bool {{ $value ? 'dynamic-table-bool-true' : 'dynamic-table-bool-false' }}" title="{{ $value ? __('dynamic-table::table.yes') : __('dynamic-table::table.no') }}">
        {{ $value ? '✓' : '✕' }}
    </span>
@elseif ($type === 'enum')
    <span class="{{ \Shwaeki\DynamicTable\Columns\Badge::classes($classes['badge'] ?? 'dynamic-table-badge', (string) $value) }}">{{ $value }}</span>
@elseif ($type === 'image')
    <img src="{{ $value }}" alt="" class="dynamic-table-thumb" loading="lazy">
@elseif ($type === 'url')
    {{-- The ellipsis and the 40 both mirror paintCell() in core.js, so a URL
         does not change shape between the first paint and the next fetch. --}}
    <a href="{{ $value }}" class="dynamic-table-link" rel="noopener noreferrer" target="_blank">{{ \Illuminate\Support\Str::limit($value, 40, '…') }}</a>
@elseif ($type === 'email')
    <a href="mailto:{{ $value }}" class="dynamic-table-link">{{ $value }}</a>
@else
    {{ $value }}
@endif

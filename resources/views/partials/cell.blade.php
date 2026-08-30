@php
    /** Server-side cell rendering; the JS renderer mirrors this exactly. */
    $type = $column['type'] ?? 'string';
    $format = $column['format'] ?? null;
    /* $html: this row's render closure returned an Htmlable for this cell. */
    $raw = ($column['raw'] ?? false) || $format === 'raw' || ($html ?? false);
@endphp

@if ($value === null || $value === '')
    <span class="dt-null" aria-hidden="true">—</span>
@elseif ($raw)
    {!! $value !!}
@elseif ($type === 'boolean')
    <span class="dt-bool {{ $value ? 'dt-bool-true' : 'dt-bool-false' }}" title="{{ $value ? __('dynamic-table::table.yes') : __('dynamic-table::table.no') }}">
        {{ $value ? '✓' : '✕' }}
    </span>
@elseif ($type === 'enum')
    <span class="{{ $classes['badge'] ?? 'dt-badge' }} dt-badge-{{ \Illuminate\Support\Str::slug((string) $value) }}">{{ $value }}</span>
@elseif ($type === 'image')
    <img src="{{ $value }}" alt="" class="dt-thumb" loading="lazy">
@elseif ($type === 'url')
    <a href="{{ $value }}" class="dt-link" rel="noopener noreferrer" target="_blank">{{ \Illuminate\Support\Str::limit($value, 40) }}</a>
@elseif ($type === 'email')
    <a href="mailto:{{ $value }}" class="dt-link">{{ $value }}</a>
@else
    {{ $value }}
@endif

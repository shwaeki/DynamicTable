@php
    /** One toolbar button: a link goes straight there, a handler posts back. */
    $class = ($action['style'] ?? 'default') === 'primary'
        ? ($classes['buttonPrimary'] ?? '')
        : (($action['style'] ?? 'default') === 'danger' ? ($classes['buttonDanger'] ?? '') : ($classes['button'] ?? ''));

    /* The action's own classes come last, so they win where they overlap. */
    $class = trim($class.' '.($action['class'] ?? ''));
@endphp

@if (! empty($action['link']))
    <a
        href="{{ $action['href'] ?? '#' }}"
        class="{{ $class }}"
        @if (! empty($action['target'])) target="{{ $action['target'] }}" rel="noopener" @endif
    >
        @if (! empty($action['icon']))<span aria-hidden="true">{!! $action['icon'] !!}</span>@endif
        {{ $action['label'] }}
    </a>
@else
    <button type="button" class="{{ $class }}" data-dynamic-table-toolbar-action="{{ $action['name'] }}">
        @if (! empty($action['icon']))<span aria-hidden="true">{!! $action['icon'] !!}</span>@endif
        {{ $action['label'] }}
    </button>
@endif

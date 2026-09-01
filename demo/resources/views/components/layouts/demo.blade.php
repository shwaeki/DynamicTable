@props([
    'title' => 'Laravel DynamicTable',
    'nav' => [],
    'section' => 'examples',
    // A page with nothing to navigate — the builder — gets the whole width
    // instead of an empty column beside it.
    'sidebar' => true,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — Laravel DynamicTable</title>

    {{-- Bootstrap is loaded only so the Bootstrap-theme example looks right. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdn.tailwindcss.com"></script>

    @include('layouts.styles')

    {{-- Applied before first paint so switching schemes never flashes. --}}
    <script>
        (() => {
            const stored = localStorage.getItem('dynamic-table-demo-scheme') || 'auto';
            const resolved = stored === 'auto'
                ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : stored;

            const root = document.documentElement;
            if (stored !== 'auto') root.setAttribute('data-scheme', stored);
            root.setAttribute('data-bs-theme', resolved);
            root.dataset.schemeChoice = stored;
        })();
    </script>
</head>
<body>
<a class="demo-skip" href="#demo-main">{{ __('demo.skip') }}</a>

<header class="demo-header">
    <a class="demo-brand" href="{{ route('examples.index') }}">
        <span class="demo-logo" aria-hidden="true">▦</span>
        <span>
            <strong>Laravel DynamicTable</strong>
            <small>{{ __('demo.tagline') }}</small>
        </span>
    </a>

    <nav class="demo-sections" aria-label="{{ __('demo.sections') }}">
        <a href="{{ route('examples.index') }}" class="{{ $section === 'examples' ? 'is-active' : '' }}"
           @if ($section === 'examples') aria-current="page" @endif>{{ __('demo.examples') }}</a>
        <a href="{{ route('builder.show') }}" class="{{ $section === 'builder' ? 'is-active' : '' }}"
           @if ($section === 'builder') aria-current="page" @endif>{{ __('demo.builder.nav') }}</a>
        <a href="{{ route('docs.index') }}" class="{{ $section === 'docs' ? 'is-active' : '' }}"
           @if ($section === 'docs') aria-current="page" @endif>{{ __('demo.docs') }}</a>
    </nav>

    <div class="demo-header-actions">
        {{--
            One switch drives the demo chrome, Bootstrap's own components and
            every DynamicTable on the page — because the package takes its
            colours from its own tokens rather than from the theme's classes.
        --}}
        <div class="demo-switch" role="group" aria-label="{{ __('demo.scheme') }}">
            @foreach (['light', 'dark', 'auto'] as $value)
                <button type="button" data-scheme-choice="{{ $value }}">{{ __('demo.scheme_'.$value) }}</button>
            @endforeach
        </div>

        <nav class="demo-switch" aria-label="{{ __('demo.language') }}">
            @foreach (['en' => 'EN', 'ar' => 'AR', 'he' => 'HE', 'ru' => 'RU'] as $code => $label)
                <a href="{{ route('examples.locale', $code) }}"
                   class="{{ app()->getLocale() === $code ? 'is-active' : '' }}"
                   @if (app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        @if ($sidebar)
            <button type="button" class="demo-btn demo-nav-toggle" data-nav-toggle aria-expanded="false">☰</button>
        @endif
    </div>
</header>

@php
    // A section's label is normally one string, but a section with its own
    // strings (the builder) has a group instead — take its 'nav' entry rather
    // than trying to print an array.
    $sectionLabel = __('demo.'.$section);
    $sectionLabel = is_array($sectionLabel) ? ($sectionLabel['nav'] ?? $section) : $sectionLabel;
@endphp

<div class="demo-shell {{ $sidebar ? '' : 'demo-shell-wide' }}">
    @if ($sidebar)
    <aside class="demo-sidebar" data-sidebar>
        <label class="demo-visually-hidden" for="nav-search">{{ __('demo.search_'.$section) }}</label>
        <input id="nav-search" type="search" class="demo-input" placeholder="{{ __('demo.search_'.$section) }}" data-example-search autocomplete="off">

        <nav class="demo-nav" aria-label="{{ $sectionLabel }}">
            @foreach ($nav as $group)
                <div class="demo-nav-group" data-nav-group>
                    <h2 class="demo-nav-heading">{{ $group['label'] }}</h2>
                    <ul>
                        @foreach ($group['items'] as $item)
                            <li>
                                <a href="{{ $item['url'] }}"
                                   class="demo-nav-link {{ $item['active'] ? 'is-active' : '' }}"
                                   data-nav-item
                                   data-search="{{ $item['search'] }}"
                                   @if ($item['active']) aria-current="page" @endif>{{ $item['title'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            <p class="demo-nav-empty" data-nav-empty hidden>{{ __('demo.no_match') }}</p>
        </nav>
    </aside>
    @endif

    <main class="demo-main" id="demo-main">
        {{ $slot }}
    </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/php.min.js"></script>
@include('layouts.scripts')
</body>
</html>

@php
    $files = $current->files();
    $tabs = array_merge(['Blade' => null], $files);
@endphp

<x-layouts.demo :title="$current->title()" :nav="$nav" section="examples">
    <header>
        <p class="demo-eyebrow">{{ $current->categoryLabel() }}</p>
        <h1 class="demo-title">{{ $current->title() }}</h1>
        <p class="demo-lede">{{ $current->description() }}</p>
    </header>

    {{--
        The live table.

        This is the real package rendering the real table class — the demo has
        no shadow implementation. The page passes the class through a variable
        because it is a generic example page; in your own Blade you would write
        the literal form shown in the Blade tab below.
    --}}
    @if ($current->needsSeeding())
        <div class="demo-card demo-panel demo-callout">
            <p>{{ __('demo.needs_seeding') }}</p>
            <pre><code>{{ $current->seedCommand }}</code></pre>
        </div>
    @endif

    @dynamicTable($current->table)

    @if ($current->notes() !== [])
        <section class="demo-card demo-panel">
            <h2 class="demo-section-title">{{ __('demo.what_to_look_for') }}</h2>
            <ul class="demo-notes" @unless ($current->notesAreTranslated()) lang="en" dir="ltr" @endunless>
                @foreach ($current->notes() as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="demo-card demo-source">
        <h2 class="demo-visually-hidden">{{ __('demo.source') }}</h2>

        <div class="demo-tabs" role="tablist">
            @foreach (array_keys($tabs) as $index => $label)
                <button type="button"
                        class="demo-tab {{ $index === 0 ? 'is-active' : '' }}"
                        role="tab"
                        aria-selected="{{ $index === 0 ? 'true' : 'false' }}"
                        data-source-tab="{{ $label }}">{{ $label }}</button>
            @endforeach

            <button
                type="button"
                class="demo-btn demo-copy"
                data-copy
                data-copy-label="{{ __('demo.copy') }}"
                data-copied-label="{{ __('demo.copied') }}"
            >{{ __('demo.copy') }}</button>
        </div>

        {{-- highlight.js has no "blade" grammar; PHP renders the directive fine. --}}
        <div data-source-pane="Blade" dir="ltr">
            <pre><code class="language-php">{{ $current->bladeSnippet() }}</code></pre>
        </div>

        @foreach ($files as $label => $path)
            <div data-source-pane="{{ $label }}" dir="ltr" hidden>
                <div class="demo-source-path">{{ Str::after($path, base_path().DIRECTORY_SEPARATOR) }}</div>
                <pre><code class="language-php">{{ file_get_contents($path) }}</code></pre>
            </div>
        @endforeach
    </section>

    <footer class="demo-panel" style="color: var(--muted); font-size: .85rem;">
        {{ __('demo.source_note') }}
        @if (config('dynamic-table.source_url'))
            <a href="{{ rtrim(config('dynamic-table.source_url'), '/') }}/demo/app/DynamicTables/{{ class_basename($current->table) }}.php">{{ __('demo.view_source') }}</a>
        @endif
    </footer>
</x-layouts.demo>

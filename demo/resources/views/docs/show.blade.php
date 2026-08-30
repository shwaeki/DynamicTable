<x-layouts.demo :title="$current->title" :nav="$nav" section="docs">
    <article class="demo-doc">
        <header class="demo-doc-head">
            <p class="demo-eyebrow">{{ $current->group }}</p>
            <h1 class="demo-title">{{ $current->title }}</h1>
        </header>

        @if (count($outline) > 1)
            <nav class="demo-toc" aria-label="{{ __('demo.on_this_page') }}">
                <p class="demo-toc-title">{{ __('demo.on_this_page') }}</p>
                <ul>
                    @foreach ($outline as $heading)
                        <li><a href="#{{ $heading['id'] }}">{{ $heading['title'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        {{--
            Rendered from the package's own docs/*.md at request time. There is
            one copy of the documentation, it lives in the repository where it
            is reviewed in pull requests, and this page renders that — so it
            cannot drift from what ships.
        --}}
        <div class="demo-prose" dir="{{ in_array(app()->getLocale(), ['ar', 'he']) ? 'ltr' : 'auto' }}">
            {!! $html !!}
        </div>

        <footer class="demo-doc-foot">
            @if ($neighbours['previous'])
                <a class="demo-doc-nav" href="{{ $neighbours['previous']->url() }}" rel="prev">
                    <span>{{ __('demo.previous') }}</span>
                    <strong>{{ $neighbours['previous']->title }}</strong>
                </a>
            @else
                <span></span>
            @endif

            @if ($neighbours['next'])
                <a class="demo-doc-nav demo-doc-nav-next" href="{{ $neighbours['next']->url() }}" rel="next">
                    <span>{{ __('demo.next') }}</span>
                    <strong>{{ $neighbours['next']->title }}</strong>
                </a>
            @endif
        </footer>

        <p class="demo-doc-source">
            {{ __('demo.doc_source', ['file' => 'docs/'.$current->slug.'.md']) }}
        </p>
    </article>
</x-layouts.demo>

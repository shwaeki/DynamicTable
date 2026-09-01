@php
    use App\Support\BuilderOptions;
    use Shwaeki\DynamicTable\Support\Feature;

    $groups = BuilderOptions::featureGroups();
    $enabled = array_flip($options['features']);

    $choices = [
        'theme' => ['demo', 'tailwind', 'bootstrap', 'minimal', 'bordered'],
        'panels' => ['modal', 'offcanvas'],
        'responsive' => ['collapse', 'cards', 'scroll', 'none'],
        'pagination' => ['auto', 'length_aware', 'simple', 'infinite'],
        'perPage' => [5, 10, 25, 50],
        'maxHeight' => ['60vh', '80vh', 'none'],
        'direction' => ['auto', 'ltr', 'rtl'],
        'scheme' => ['auto', 'light', 'dark'],
    ];
@endphp

<x-layouts.demo :title="__('demo.builder.title')" section="builder" :sidebar="false">
    {{--
        The package's stylesheet and core module, emitted here rather than by
        the directive.

        The directive injects them at the point of use, which on this page is
        inside the preview — and the preview's contents are replaced on every
        change. Removing a <link> removes its rules with it, so the table would
        lose the package's CSS the first time an option was toggled. Claimed
        up here, the directive below emits nothing.
    --}}
    @dynamicTableStyles
    @dynamicTableScripts

    <header>
        <p class="demo-eyebrow">{{ __('demo.builder.nav') }}</p>
        <h1 class="demo-title">{{ __('demo.builder.title') }}</h1>
        <p class="demo-lede">{{ __('demo.builder.lede') }}</p>
    </header>

    <div class="builder">
        {{--
            The controls. Every one of them maps onto exactly one property of a
            DynamicTable — which is the point being made: there is no builder
            format, only the class you would have written.
        --}}
        <form class="demo-card demo-panel builder-controls" data-builder-form>
            @foreach ($groups as $label => $features)
                <fieldset class="builder-group">
                    <legend class="demo-section-title">{{ $label }}</legend>

                    <div class="builder-checks">
                        @foreach ($features as $feature)
                            <label class="builder-check">
                                <input
                                    type="checkbox"
                                    name="features[]"
                                    value="{{ $feature }}"
                                    @checked(isset($enabled[$feature]))
                                >
                                <span>{{ str_replace('_', ' ', $feature) }}</span>
                                @if (in_array($feature, Feature::DEFAULTS, true))
                                    <span class="builder-tag">{{ __('demo.builder.default') }}</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            <fieldset class="builder-group">
                <legend class="demo-section-title">{{ __('demo.builder.presentation') }}</legend>

                <div class="builder-fields">
                    @foreach ($choices as $name => $values)
                        <label class="builder-field">
                            <span>{{ __('demo.builder.fields.'.$name) }}</span>
                            <select class="demo-input" name="{{ $name }}">
                                @foreach ($values as $value)
                                    <option value="{{ $value }}" @selected((string) $options[$name] === (string) $value)>{{ $value }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach

                    <label class="builder-check builder-field">
                        <input type="checkbox" name="sticky" value="1" @checked($options['sticky'])>
                        <span>{{ __('demo.builder.fields.sticky') }}</span>
                    </label>

                    <label class="builder-check builder-field">
                        <input type="checkbox" name="summary" value="1" @checked($options['summary'])>
                        <span>{{ __('demo.builder.fields.summary') }}</span>
                    </label>
                </div>
            </fieldset>

            <p class="dynamic-table-hint builder-note">{{ __('demo.builder.implies') }}</p>
        </form>

        <div class="builder-output">
            {{-- The real table, re-rendered by the package on every change. --}}
            <div class="demo-card builder-preview" data-builder-preview>
                @dynamicTable(App\DynamicTables\BuilderTable::class)
            </div>

            <section class="demo-card demo-source builder-code">
                <div class="demo-tabs">
                    <span class="demo-tab is-active">OrdersTable.php</span>
                    <button type="button" class="demo-btn demo-copy" data-builder-copy>{{ __('demo.copy') }}</button>
                </div>
                <pre><code class="language-php" data-builder-code>{{ $code }}</code></pre>
            </section>
        </div>
    </div>

    <script>
        (() => {
            const form = document.querySelector('[data-builder-form]');
            const preview = document.querySelector('[data-builder-preview]');
            const code = document.querySelector('[data-builder-code]');

            if (! form || ! preview || ! code) return;

            let pending = null;

            const render = async () => {
                const data = new FormData(form);
                const options = {
                    features: data.getAll('features[]'),
                    sticky: data.get('sticky') ? 1 : 0,
                    summary: data.get('summary') ? 1 : 0,
                };

                for (const name of ['theme', 'panels', 'responsive', 'pagination', 'perPage', 'maxHeight', 'direction', 'scheme']) {
                    options[name] = data.get(name);
                }

                pending?.abort();
                const controller = new AbortController();
                pending = controller;

                preview.setAttribute('aria-busy', 'true');

                try {
                    const response = await fetch(@json(route('builder.preview')), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        },
                        body: JSON.stringify({ options }),
                        signal: controller.signal,
                    });

                    const payload = await response.json();

                    preview.innerHTML = payload.html;

                    // The fragment carries its own boot payload; the core module
                    // is already loaded, so it only needs to be pointed at the
                    // new element.
                    window.DynamicTable?.boot(preview);

                    code.textContent = payload.code;
                    window.hljs?.highlightElement(code);
                } catch (error) {
                    if (error.name !== 'AbortError') console.error(error);
                } finally {
                    preview.removeAttribute('aria-busy');
                }
            };

            form.addEventListener('change', render);

            document.querySelector('[data-builder-copy]')?.addEventListener('click', async (event) => {
                await navigator.clipboard.writeText(code.textContent);

                const button = event.currentTarget;
                const original = button.textContent;

                button.textContent = @json(__('demo.copied'));
                setTimeout(() => { button.textContent = original; }, 1200);
            });
        })();
    </script>
</x-layouts.demo>

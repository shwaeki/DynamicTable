@php
    /*
     * In a real screen the controller supplies these. Kept here so the partial
     * is one droppable file — it is the two data- attributes below that this
     * example is about, not where the options come from.
     */
    $statuses = collect(App\Enums\OrderStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]);
    $countries = App\Models\Customer::query()->distinct()->orderBy("country")->pluck("country")->filter();
@endphp
{{--
    A filter bar in the host application's own markup.

    Two attributes are the whole integration:

      data-dynamic-table-param   the parameter this control sets
      data-dynamic-table-table   the table key it sets it on

    The table picks the controls up on load, applies them and reloads itself.
    Wrapping the bar in data-dynamic-table-params="<key>" lets the controls
    inside drop the second attribute; both spellings are shown below.

    Nothing here is package markup — the classes are the demo's own, and in a
    Bootstrap or Tailwind admin they would be that framework's instead.
--}}
<section class="demo-card demo-panel" data-dynamic-table-params="demo_orders">
    <h2 class="demo-section-title">{{ __('demo.param_filters.title') }}</h2>

    <div class="demo-filter-bar">
        <label class="demo-filter">
            <span>{{ __('demo.param_filters.status') }}</span>
            <select data-dynamic-table-param="status">
                <option value="">{{ __('demo.param_filters.any') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="demo-filter">
            <span>{{ __('demo.param_filters.country') }}</span>
            {{-- Spelled out in full, so it would work outside the wrapper too. --}}
            <select data-dynamic-table-param="country" data-dynamic-table-table="demo_orders">
                <option value="">{{ __('demo.param_filters.any') }}</option>
                @foreach ($countries as $country)
                    <option value="{{ $country }}" @selected(request('country') === $country)>{{ $country }}</option>
                @endforeach
            </select>
        </label>

        <label class="demo-filter">
            <span>{{ __('demo.param_filters.min_total') }}</span>
            <input type="number" min="0" step="100" data-dynamic-table-param="min_total" value="{{ request('min_total') }}">
        </label>

        <label class="demo-filter">
            <span>{{ __('demo.param_filters.placed') }}</span>
            <select data-dynamic-table-param="placed_period">
                <option value="">{{ __('demo.param_filters.any') }}</option>
                <option value="week" @selected(request('placed_period') === 'week')>{{ __('demo.param_filters.week') }}</option>
                <option value="month" @selected(request('placed_period') === 'month')>{{ __('demo.param_filters.month') }}</option>
                <option value="year" @selected(request('placed_period') === 'year')>{{ __('demo.param_filters.year') }}</option>
            </select>
        </label>

        {{-- Clears every control bound to this table, and reloads it once. --}}
        <button type="button" class="demo-filter-reset" data-dynamic-table-params-reset="demo_orders">
            {{ __('demo.param_filters.reset') }}
        </button>
    </div>
</section>

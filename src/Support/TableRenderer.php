<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Views\ViewEngine;

/**
 * Backs the @dynamicTable directive.
 *
 * The first page of data is rendered server-side inside the boot payload, so
 * the table is populated on first paint with zero extra requests.
 */
class TableRenderer
{
    public function __construct(
        protected TableRegistry $registry,
        protected TablePayload $payload,
        protected AssetManager $assets,
        protected ViewEngine $views,
    ) {}

    /**
     * @param  class-string<DynamicTable>|string|DynamicTable  $table
     * @param  array<string, mixed>  $options
     */
    public function render(string|DynamicTable $table, array $options = []): HtmlString
    {
        $table = $table instanceof DynamicTable ? $table : $this->registry->resolve($table);

        if (! $table->can('view')) {
            return new HtmlString(
                View::make('dynamic-table::partials.denied', ['table' => $table])->render()
            );
        }

        $state = TableState::fromArray($this->initialInput($table, $options), $table);
        $data = $this->payload->data($table, $state);
        $boot = $this->payload->boot($table, $state, $data);

        $theme = $options['theme'] ?? $table->theme();
        $view = $this->themeView($theme);

        return new HtmlString(View::make($view, [
            'table' => $table,
            'boot' => $boot,
            'state' => $state,
            'assets' => $this->assets,
            'options' => $options,
            'id' => 'dt-'.$table->key().'-'.substr(md5(uniqid('', true)), 0, 6),
        ])->render());
    }

    /**
     * The state a table boots with: table defaults, then the default saved
     * view, then URL parameters when url_state is enabled.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function initialInput(DynamicTable $table, array $options): array
    {
        $input = [];

        if ($table->hasFeature(Feature::VIEWS)) {
            $default = $this->views->defaultFor($table);

            if ($default !== null) {
                $input = $default->configuration ?? [];
                $input['view'] = (string) $default->getKey();
            }
        }

        if ($table->hasFeature(Feature::URL_STATE)) {
            $query = request()->query();
            $prefix = $table->key().'_';

            foreach (['search', 'page', 'perPage', 'sort', 'columns', 'filters', 'group', 'trashed', 'view'] as $field) {
                foreach ([$prefix.$field, $field] as $candidate) {
                    if (array_key_exists($candidate, $query)) {
                        $input[$field] = $query[$candidate];

                        break;
                    }
                }
            }

            if (is_string($input['filters'] ?? null)) {
                $decoded = json_decode($input['filters'], true);
                $input['filters'] = is_array($decoded) ? $decoded : [];
            }

            if (is_string($input['columns'] ?? null)) {
                $input['columns'] = array_filter(explode(',', $input['columns']));
            }
        }

        foreach (['search', 'sort', 'perPage', 'columns', 'filters'] as $field) {
            if (array_key_exists($field, $options)) {
                $input[$field] = $options[$field];
            }
        }

        return $input;
    }

    /**
     * A theme is normally just a class map, so every theme shares one template.
     * A theme that needs different markup can still ship its own Blade file at
     * dynamic-table::themes.{name}.table and it will be preferred.
     */
    protected function themeView(string $theme): string
    {
        $candidate = 'dynamic-table::themes.'.$theme.'.table';

        return View::exists($candidate) ? $candidate : 'dynamic-table::table';
    }
}

<?php

namespace Shwaeki\DynamicTable\View\Components;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\View\Component;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\TableRenderer;

/**
 * <x-dynamic-table :table="UsersTable::class" />
 *
 * The same thing @dynamicTable does, said the way a Blade component says it.
 * It exists because a directive is awkward in the places tables increasingly
 * live: inside a Livewire component's view, inside another component's slot,
 * or anywhere the arguments are variables rather than literals.
 *
 *     <x-dynamic-table :table="UsersTable::class" />
 *     <x-dynamic-table :table="$table" :params="['status' => 'open']" />
 *     <x-dynamic-table :table="UsersTable::class" theme="bootstrap" />
 *
 * Everything the directive accepts as its options array is an attribute here.
 */
class Table extends Component
{
    /**
     * @param  class-string<DynamicTable>|DynamicTable  $table
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options  anything else TableRenderer accepts
     */
    public function __construct(
        public string|DynamicTable $table,
        public array $params = [],
        public ?string $theme = null,
        public array $options = [],
    ) {}

    public function render(): Htmlable
    {
        $options = $this->options;

        if ($this->params !== []) {
            $options['params'] = $this->params;
        }

        if ($this->theme !== null) {
            $options['theme'] = $this->theme;
        }

        return app(TableRenderer::class)->render($this->table, $options);
    }
}

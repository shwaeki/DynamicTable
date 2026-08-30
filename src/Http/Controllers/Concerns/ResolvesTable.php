<?php

namespace Shwaeki\DynamicTable\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Exceptions\DynamicTableException;
use Shwaeki\DynamicTable\Support\TableRegistry;
use Shwaeki\DynamicTable\Support\TableState;

trait ResolvesTable
{
    /**
     * Resolve the table named by the request.
     *
     * The request carries a key, never a class name, and the key is looked up
     * in the registry allowlist — so an attacker cannot instantiate arbitrary
     * classes through this endpoint.
     */
    protected function table(Request $request): DynamicTable
    {
        $key = (string) $request->input('table', '');

        abort_if($key === '' || ! preg_match('/^[a-z0-9_\-]{1,100}$/i', $key), 400, 'Invalid table key.');

        try {
            $table = app(TableRegistry::class)->resolve($key);
        } catch (DynamicTableException) {
            abort(404, 'Unknown table.');
        }

        abort_unless($table->can('view'), 403);

        return $table;
    }

    protected function state(Request $request, DynamicTable $table): TableState
    {
        $input = $request->input('state');

        // The print page is a GET, so its state arrives as JSON in the query
        // string rather than as a decoded body. It is validated by
        // TableState either way — this only gets it into array shape.
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            $input = is_array($decoded) ? $decoded : [];
        }

        return TableState::fromArray(is_array($input) ? $input : [], $table);
    }
}

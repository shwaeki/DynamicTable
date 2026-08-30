<?php

namespace App\Http\Controllers;

use App\DynamicTables\BuilderTable;
use App\Support\BuilderOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Shwaeki\DynamicTable\Support\AssetManager;

/**
 * The builder: switch options on and off and watch the real table change.
 *
 * The preview is not a picture of a table — it is the table, rendered by the
 * package from the same options the code panel prints. That is the only way a
 * page like this stays honest as the package changes.
 */
class BuilderController extends Controller
{
    public function show(Request $request): View
    {
        $options = BuilderOptions::current();

        return view('builder.show', [
            'options' => $options,
            'code' => BuilderOptions::code($options),
        ]);
    }

    /**
     * One option changed: re-render the table and the code, nothing else.
     *
     * A full page reload would work and would be simpler, but it would also
     * throw away the reader's scroll position and any state they had set up in
     * the table they are looking at.
     */
    public function preview(Request $request): JsonResponse
    {
        $options = BuilderOptions::store((array) $request->input('options', []));

        // The stylesheet and the core module are already on the page; injecting
        // them again into a fragment would put a second copy of both into the
        // document.
        config()->set('dynamic-table.assets.inject', false);

        // The table configures itself from the session in its constructor, so
        // it has to be built after the options were stored.
        $html = view('builder.preview', ['table' => app(BuilderTable::class)])->render();

        app(AssetManager::class)->reset();

        return response()->json([
            'html' => $html,
            'code' => BuilderOptions::code($options),
        ]);
    }
}

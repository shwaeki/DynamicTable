<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Views\UserDirectory;
use Shwaeki\DynamicTable\Views\ViewEngine;

class ViewController extends Controller
{
    use ResolvesTable;

    public function __construct(
        protected ViewEngine $views,
        protected UserDirectory $directory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::VIEWS);

        return response()->json([
            'views' => $this->views->payloadFor($table),
            'canManageSystem' => $this->views->canManageSystemViews($table),
            'sharing' => $this->views->sharingEnabled(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::VIEWS);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'system' => ['sometimes', 'boolean'],
        ]);

        $view = $this->views->create(
            $table,
            $validated['name'],
            $this->state($request, $table),
            (bool) ($validated['system'] ?? false),
        );

        return response()->json(['view' => $view->toPayload()], 201);
    }

    public function update(Request $request, string $view): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::VIEWS);

        $model = $this->views->find($table, $view);
        abort_if($model === null || $model->exists === false, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'saveState' => ['sometimes', 'boolean'],
        ]);

        $updated = $this->views->update(
            $table,
            $model,
            $validated['name'] ?? null,
            ($validated['saveState'] ?? false) ? $this->state($request, $table) : null,
        );

        return response()->json(['view' => $updated->toPayload()]);
    }

    public function destroy(Request $request, string $view): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::VIEWS);

        $model = $this->views->find($table, $view);
        abort_if($model === null || $model->exists === false, 404);

        $this->views->delete($table, $model);

        return response()->json(['ok' => true]);
    }

    /**
     * Replace the people a view is shared with.
     *
     * Only the owner may share, and sharing grants read access alone.
     */
    public function share(Request $request, string $view): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::VIEWS);

        $model = $this->views->find($table, $view);
        abort_if($model === null || $model->exists === false, 404);

        $validated = $request->validate([
            'users' => ['present', 'array', 'max:500'],
            'users.*' => ['required', 'string', 'max:64'],
        ]);

        $this->views->share($table, $model, $validated['users']);

        return response()->json([
            'ok' => true,
            'sharedWith' => $this->views->sharedWith($model),
            'views' => $this->views->payloadFor($table),
        ]);
    }

    /** Who a view is currently shared with, plus a directory search. */
    public function shares(Request $request, string $view): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::VIEWS);

        $model = $this->views->find($table, $view);
        abort_if($model === null || $model->exists === false, 404);

        // The directory is only searchable by someone who can actually share
        // this view, so it never becomes a general user-enumeration endpoint.
        abort_unless($model->isOwnedBy($this->views->userId()), 403);
        abort_unless($this->views->sharingEnabled(), 400, 'View sharing is disabled.');

        $search = trim((string) $request->input('search', ''));

        return response()->json([
            'sharedWith' => $this->views->sharedWith($model),
            'candidates' => $this->directory->search($search, $this->views->userId()),
        ]);
    }

    public function setDefault(Request $request, string $view): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::VIEWS);

        $model = $this->views->find($table, $view);
        abort_if($model === null || $model->exists === false, 404);

        // Sending default=false clears it, so the star is a toggle.
        $this->views->setDefault($table, $model, $request->boolean('default', true));

        return response()->json([
            'ok' => true,
            'views' => $this->views->payloadFor($table),
        ]);
    }
}

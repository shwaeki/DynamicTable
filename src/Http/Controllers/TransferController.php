<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Shwaeki\DynamicTable\Http\Controllers\Concerns\ResolvesTable;
use Shwaeki\DynamicTable\Modules\Export\ExportJob;
use Shwaeki\DynamicTable\Modules\Export\ExportManager;
use Shwaeki\DynamicTable\Modules\Import\ImportJob;
use Shwaeki\DynamicTable\Modules\Import\ImportManager;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\TransferProgress;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export and import endpoints.
 *
 * Small jobs run inline and stream straight to the browser; anything past the
 * configured threshold is queued, with poll-based progress so no broadcasting
 * setup is required.
 */
class TransferController extends Controller
{
    use ResolvesTable;

    public function __construct(
        protected ExportManager $exports,
        protected ImportManager $imports,
    ) {}

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::EXPORT);
        abort_unless($table->can('export'), 403);

        $scope = (string) $request->input('scope', 'view');
        abort_unless(in_array($scope, ['page', 'view', 'all', 'selected'], true), 422);

        $format = (string) $request->input('format', 'csv');
        abort_unless(in_array($format, $this->exports->supportedFormats(), true), 422);

        $state = $this->state($request, $table);

        if ($scope === 'selected') {
            abort_unless($state->hasSelection(), 422, __('dynamic-table::table.errors.no_selection'));
        }

        $threshold = (int) config('dynamic-table.excel.queue_threshold', 5000);
        $estimate = $this->exports->estimate($table, $state, $scope);

        if ($threshold > 0 && $estimate > $threshold) {
            $progressId = TransferProgress::start('export', $table->key(), $estimate);

            ExportJob::dispatch($table->key(), $request->input('state', []), $scope, $format, $progressId);

            return response()->json([
                'queued' => true,
                'progress' => $progressId,
                'total' => $estimate,
            ], 202);
        }

        // The UI asks first so it knows whether to show a progress bar or let
        // the browser handle a direct download.
        if ($request->boolean('probe')) {
            return response()->json(['queued' => false, 'total' => $estimate]);
        }

        return $this->exports->stream($table, $state, $scope, $format);
    }

    public function template(Request $request): BinaryFileResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::IMPORT);
        abort_unless($table->can('import'), 403);

        $format = (string) $request->input('format', 'csv');
        $path = $this->imports->template($table, $format);

        return response()->download($path)->deleteFileAfterSend();
    }

    /** Upload a file and get back headings, a preview and a suggested mapping. */
    public function analyze(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::IMPORT);
        abort_unless($table->can('import'), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:csv,txt,xlsx,xls'],
        ]);

        $stored = $request->file('file')->store('dynamic-table/imports');
        $path = Storage::path($stored);

        return response()->json($this->imports->analyze($table, $path) + [
            'token' => $this->tokenFor($stored),
            'file' => $stored,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::IMPORT);
        abort_unless($table->can('import'), 403);

        $validated = $request->validate([
            'file' => ['required', 'string'],
            'token' => ['required', 'string'],
            'mapping' => ['present', 'array'],
            'mode' => ['sometimes', 'in:create,update,upsert'],
            'matchBy' => ['sometimes', 'nullable', 'string'],
        ]);

        // The stored path came from our own analyze() response; the token stops
        // a client from pointing the importer at an arbitrary file.
        abort_unless(
            hash_equals($this->tokenFor($validated['file']), $validated['token']),
            403,
        );

        $path = Storage::path($validated['file']);
        abort_unless(is_file($path), 404);

        $mapping = [];

        foreach ($validated['mapping'] as $index => $columnKey) {
            $mapping[(int) $index] = is_string($columnKey) && $columnKey !== '' ? $columnKey : null;
        }

        $mode = $validated['mode'] ?? 'create';
        $options = ['matchBy' => $validated['matchBy'] ?? null];

        $threshold = (int) config('dynamic-table.excel.queue_threshold', 5000);
        $total = $this->imports->readerFor($path)->countRows($path) ?? 0;

        if ($threshold > 0 && $total > $threshold) {
            $progressId = TransferProgress::start('import', $table->key(), $total);

            ImportJob::dispatch($table->key(), $path, $mapping, $mode, $options, $progressId);

            return response()->json([
                'queued' => true,
                'progress' => $progressId,
                'total' => $total,
            ], 202);
        }

        $summary = $this->imports->run($table, $path, $mapping, $mode, $options);

        @unlink($path);

        return response()->json(['queued' => false] + $summary);
    }

    public function progress(Request $request): JsonResponse
    {
        $this->table($request);

        $id = (string) $request->input('id', '');
        $progress = TransferProgress::get($id);

        abort_if($progress === null, 404);

        if (($progress['file'] ?? null) !== null) {
            $progress['url'] = route('dynamic-table.download', [
                'table' => $request->input('table'),
                'id' => $id,
            ]);
        }

        return response()->json($progress);
    }

    public function download(Request $request): StreamedResponse
    {
        $table = $this->table($request);
        abort_unless($table->can('export'), 403);

        $progress = TransferProgress::get((string) $request->input('id', ''));

        abort_if($progress === null || ($progress['file'] ?? null) === null, 404);
        abort_unless($progress['table'] === $table->key(), 403);

        $disk = Storage::disk(config('dynamic-table.excel.disk'));

        abort_unless($disk->exists($progress['file']), 404);

        return $disk->download($progress['file'], (string) $progress['filename']);
    }

    protected function tokenFor(string $path): string
    {
        return hash_hmac('sha256', $path, (string) config('app.key'));
    }
}

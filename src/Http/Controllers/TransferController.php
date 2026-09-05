<?php

namespace Shwaeki\DynamicTable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Shwaeki\DynamicTable\DynamicTable;
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
        abort_unless($table->can('export'), 403, __('dynamic-table::table.errors.forbidden'));

        $scope = (string) $request->input('scope', 'view');
        abort_unless(in_array($scope, ['page', 'view', 'all', 'selected'], true), 422, __('dynamic-table::table.errors.invalid_scope'));

        $format = (string) $request->input('format', $this->exports->defaultFormat());
        abort_unless(in_array($format, $this->exports->supportedFormats(), true), 422, __('dynamic-table::table.errors.unsupported_format', ['format' => strtoupper($format)]));

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
        abort_unless($table->can('import'), 403, __('dynamic-table::table.errors.forbidden'));

        $format = (string) $request->input('format', $this->exports->defaultFormat());
        $path = $this->imports->template($table, $format);

        return response()->download($path)->deleteFileAfterSend();
    }

    /** Upload a file and get back headings, a preview and a suggested mapping. */
    public function analyze(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::IMPORT);
        abort_unless($table->can('import'), 403, __('dynamic-table::table.errors.forbidden'));

        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:csv,txt,xlsx,xls'],
        ]);

        $stored = $request->file('file')->store('dynamic-table/imports');
        $path = Storage::path($stored);

        $analysis = $this->imports->analyze($table, $path);

        /*
         * The mapping this person used last time, where the file still has the
         * heading it was for.
         *
         * It replaces the guess column by column rather than wholesale: a file
         * with one new column keeps every decision already made about the
         * others, and the new one still gets the suggestion.
         */
        $remembered = $this->recallMapping($table, $analysis['headings']);

        if ($remembered !== []) {
            $analysis['mapping'] = $remembered['columns'] + $analysis['mapping'];
            $analysis['remembered'] = ['mode' => $remembered['mode'], 'matchBy' => $remembered['matchBy']];
        }

        // Kept so the mapping can be stored by heading when the import runs;
        // the browser sends indexes, and indexes move.
        if (app()->bound('session') && app('session')->isStarted()) {
            session()->put('dynamic-table.import.headings.'.$table->key(), $analysis['headings']);
        }

        return response()->json($analysis + [
            'token' => $this->tokenFor($stored),
            'file' => $stored,
        ]);
    }

    /**
     * The remembered mapping, translated back onto this file's column indexes.
     *
     * @param  list<string>  $headings
     * @return array{columns: array<int, string|null>, mode: string, matchBy: string|null}|array{}
     */
    protected function recallMapping(DynamicTable $table, array $headings): array
    {
        if (! app()->bound('session') || ! app('session')->isStarted()) {
            return [];
        }

        $stored = session('dynamic-table.import.mapping.'.$table->key());

        if (! is_array($stored) || ! is_array($stored['columns'] ?? null)) {
            return [];
        }

        $columns = [];

        foreach ($headings as $index => $heading) {
            if (array_key_exists($heading, $stored['columns'])) {
                $columns[(int) $index] = $stored['columns'][$heading];
            }
        }

        if ($columns === []) {
            return [];
        }

        return [
            'columns' => $columns,
            'mode' => is_string($stored['mode'] ?? null) ? $stored['mode'] : 'create',
            'matchBy' => is_string($stored['matchBy'] ?? null) ? $stored['matchBy'] : null,
        ];
    }

    public function import(Request $request): JsonResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::IMPORT);
        abort_unless($table->can('import'), 403, __('dynamic-table::table.errors.forbidden'));

        $validated = $request->validate([
            'file' => ['required', 'string'],
            'token' => ['required', 'string'],
            'mapping' => ['present', 'array'],
            'mode' => ['sometimes', 'in:create,update,upsert'],
            'matchBy' => ['sometimes', 'nullable', 'string'],
            'dry' => ['sometimes', 'boolean'],
        ]);

        $dry = (bool) ($validated['dry'] ?? false);

        // The stored path came from our own analyze() response; the token stops
        // a client from pointing the importer at an arbitrary file.
        abort_unless(
            hash_equals($this->tokenFor($validated['file']), $validated['token']),
            403,
            __('dynamic-table::table.errors.forbidden'),
        );

        $path = Storage::path($validated['file']);

        // Both import paths delete the upload when they are done with it, so a
        // second Start import on a dialog still holding the first one's file
        // lands here. It is the commonest way to reach this line, and it has
        // an answer — choose the file again — so it says so.
        abort_unless(is_file($path), 404, __('dynamic-table::table.errors.upload_expired'));

        $mapping = [];

        foreach ($validated['mapping'] as $index => $columnKey) {
            $mapping[(int) $index] = is_string($columnKey) && $columnKey !== '' ? $columnKey : null;
        }

        $mode = $validated['mode'] ?? 'create';
        $options = ['matchBy' => $validated['matchBy'] ?? null];

        // Update mode writes only what it is given, so a column it never
        // mentions keeps the value the record already has. Create and upsert
        // can both insert, and an insert has to carry every NOT NULL column.
        if ($mode !== 'update') {
            $missing = $this->imports->missingRequired($table, $mapping);

            abort_unless($missing === [], 422, __('dynamic-table::table.errors.missing_required', [
                'fields' => implode(', ', $missing),
            ]));
        }

        // Both modes that match against existing records need the column they
        // match on to be in the file. Unchecked, the lookup silently finds
        // nothing every time and upsert inserts a duplicate of every row.
        if ($mode !== 'create') {
            $unmapped = $this->imports->matchUnmapped($table, $mapping, $options['matchBy']);

            abort_unless($unmapped === null, 422, __('dynamic-table::table.errors.match_unmapped', [
                'field' => $unmapped,
            ]));
        }

        $threshold = (int) config('dynamic-table.excel.queue_threshold', 5000);
        $total = $this->imports->readerFor($path)->countRows($path) ?? 0;

        /*
         * A dry run stays in this request, whatever the size.
         *
         * Queueing it would answer "what will this do?" with "ask again
         * later", and the answer would arrive after the person had gone. The
         * upload is kept as well, because the real import is the next thing
         * they will press.
         */
        if ($dry) {
            $summary = $this->imports->run($table, $path, $mapping, $mode, $options + ['dryRun' => true]);

            return response()->json(['queued' => false] + $this->signReport($summary, $table->key()));
        }

        // What was mapped, so the next upload of the same shape starts where
        // this one ended. Stored only once the mapping has passed every check
        // above — a mapping the importer would refuse is not worth offering
        // back.
        $this->rememberMapping($table, $mapping, $mode, $options['matchBy'] ?? null);

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

        return response()->json(['queued' => false] + $this->signReport($summary, $table->key()));
    }

    /**
     * Remember how this table's columns were mapped, for the next upload.
     *
     * Keyed by heading name rather than by position, because the same export
     * re-run tomorrow may have gained a column and shifted every index by one.
     * The session is the store: a mapping is a habit, private to one person,
     * and worth exactly as much as their last upload — the same reasoning as
     * StateMemory, and no migration either.
     *
     * @param  array<int, string|null>  $mapping
     */
    protected function rememberMapping(DynamicTable $table, array $mapping, string $mode, ?string $matchBy): void
    {
        if (! app()->bound('session') || ! app('session')->isStarted()) {
            return;
        }

        $headings = session('dynamic-table.import.headings.'.$table->key());

        if (! is_array($headings)) {
            return;
        }

        $byHeading = [];

        foreach ($mapping as $index => $columnKey) {
            $heading = $headings[$index] ?? null;

            if (is_string($heading) && $heading !== '') {
                $byHeading[$heading] = $columnKey;
            }
        }

        session()->put('dynamic-table.import.mapping.'.$table->key(), [
            'columns' => $byHeading,
            'mode' => $mode,
            'matchBy' => $matchBy,
        ]);
    }

    /**
     * Attach a one-file download token to an import summary.
     *
     * The report key is the server's own, and the token is an HMAC of it, so a
     * client cannot point this at any other file — the same guarantee, and the
     * same mechanism, as the uploaded file it came from.
     *
     * The table key is signed with it, so a report can only be fetched back
     * through the table that produced it. Otherwise someone who may import one
     * table could fetch another table's rejected rows, which are that table's
     * data.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    protected function signReport(array $summary, string $tableKey): array
    {
        if (is_string($summary['report'] ?? null)) {
            $summary['reportToken'] = $this->reportToken($summary['report'], $tableKey);
        }

        return $summary;
    }

    protected function reportToken(string $report, string $tableKey): string
    {
        return $this->tokenFor($tableKey.'|'.$report);
    }

    /**
     * Download the error report for an import that rejected rows.
     *
     * Four gates, and it needs all of them: the table resolves from the
     * registry, import is enabled on it, this viewer may import, and the key
     * carries a valid HMAC. The prefix check is belt and braces — with a
     * forged token impossible, it only bounds the damage of a future mistake
     * in the signing.
     */
    public function errors(Request $request): StreamedResponse
    {
        $table = $this->table($request);
        $table->requireFeature(Feature::IMPORT);
        abort_unless($table->can('import'), 403, __('dynamic-table::table.errors.forbidden'));

        $validated = $request->validate([
            'report' => ['required', 'string'],
            'token' => ['required', 'string'],
        ]);

        abort_unless(
            hash_equals(
                $this->reportToken($validated['report'], $table->key()),
                $validated['token'],
            ),
            403,
            __('dynamic-table::table.errors.forbidden'),
        );

        abort_unless(
            str_starts_with($validated['report'], ImportManager::reportDirectory().'/'),
            403,
            __('dynamic-table::table.errors.forbidden'),
        );

        $disk = Storage::disk(config('dynamic-table.excel.disk'));

        abort_unless($disk->exists($validated['report']), 404, __('dynamic-table::table.errors.file_expired'));

        return $disk->download($validated['report'], basename($validated['report']));
    }

    public function progress(Request $request): JsonResponse
    {
        $this->table($request);

        $id = (string) $request->input('id', '');
        $progress = TransferProgress::get($id);

        abort_if($progress === null, 404, __('dynamic-table::table.errors.progress_expired'));

        if (($progress['file'] ?? null) !== null) {
            $progress['url'] = route('dynamic-table.download', [
                'table' => $request->input('table'),
                'id' => $id,
            ]);
        }

        // "file" is a path on the application's own disk, and the browser has
        // a signed download URL instead. Sending it would tell every viewer
        // where exports are kept, for nothing they could use.
        unset($progress['file']);

        // A queued import finishes with its summary in here, so the error
        // report needs the same token the inline reply hands out.
        return response()->json($this->signReport($progress, (string) $request->input('table')));
    }

    public function download(Request $request): StreamedResponse
    {
        $table = $this->table($request);
        abort_unless($table->can('export'), 403, __('dynamic-table::table.errors.forbidden'));

        $progress = TransferProgress::get((string) $request->input('id', ''));

        abort_if($progress === null || ($progress['file'] ?? null) === null, 404, __('dynamic-table::table.errors.file_expired'));
        abort_unless($progress['table'] === $table->key(), 403, __('dynamic-table::table.errors.forbidden'));

        $disk = Storage::disk(config('dynamic-table.excel.disk'));

        abort_unless($disk->exists($progress['file']), 404, __('dynamic-table::table.errors.file_expired'));

        return $disk->download($progress['file'], (string) $progress['filename']);
    }

    protected function tokenFor(string $path): string
    {
        return hash_hmac('sha256', $path, (string) config('app.key'));
    }
}

<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Shwaeki\DynamicTable\Modules\Export\ExportJob;
use Shwaeki\DynamicTable\Modules\Export\ExportManager;
use Shwaeki\DynamicTable\Modules\Export\Writers\XlsxWriter;
use Shwaeki\DynamicTable\Modules\Import\ImportJob;
use Shwaeki\DynamicTable\Modules\Import\ImportManager;
use Shwaeki\DynamicTable\Modules\Import\Readers\XlsxReader;
use Shwaeki\DynamicTable\Tests\Fixtures\Department;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\User;

beforeEach(fn () => seedUsers());

function streamOf(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return ob_get_clean();
}

it('exports the current view with its columns, filters and sort', function (): void {
    $response = $this->post(route('dynamic-table.export'), [
        'table' => 'full_users',
        'scope' => 'view',
        'format' => 'csv',
        'state' => [
            'columns' => ['name', 'department__name'],
            'filters' => [['field' => 'department.name', 'operator' => 'equals', 'value' => 'IT']],
            'sort' => [['field' => 'name', 'direction' => 'asc']],
        ],
    ]);

    $response->assertOk();

    $csv = streamOf($response);
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));

    expect($lines[0])->toContain('Name')
        ->and($lines[0])->toContain('Department')
        ->and($lines)->toHaveCount(5) // heading + 4 IT users
        // fputcsv quotes any value containing a space; that is valid CSV.
        ->and($lines[1])->toBe('"User 01",IT');
});

it('exports only the selected records', function (): void {
    $ids = User::query()->limit(2)->pluck('id')->all();

    $response = $this->post(route('dynamic-table.export'), [
        'table' => 'full_users',
        'scope' => 'selected',
        'format' => 'csv',
        'state' => ['columns' => ['name'], 'selection' => ['mode' => 'include', 'ids' => $ids]],
    ]);

    expect(array_filter(explode("\n", str_replace("\r", '', streamOf($response)))))->toHaveCount(3);
});

it('neutralises formula injection in exported values', function (): void {
    User::first()->update(['name' => '=cmd|calc']);

    $response = $this->post(route('dynamic-table.export'), [
        'table' => 'full_users',
        'scope' => 'view',
        'format' => 'csv',
        'state' => ['columns' => ['name']],
    ]);

    expect(streamOf($response))->toContain("'=cmd|calc");
});

it('queues an export that exceeds the threshold', function (): void {
    Queue::fake();
    config()->set('dynamic-table.excel.queue_threshold', 5);

    $this->postJson(route('dynamic-table.export'), [
        'table' => 'full_users',
        'scope' => 'view',
        'format' => 'csv',
        'state' => [],
    ])
        ->assertStatus(202)
        ->assertJsonPath('queued', true)
        ->assertJsonPath('total', 12);

    Queue::assertPushed(ExportJob::class);
});

it('refuses export when the feature is off', function (): void {
    $this->postJson(route('dynamic-table.export'), ['table' => 'users', 'scope' => 'view'])
        ->assertStatus(403);
});

it('builds an import template from the importable columns', function (): void {
    $path = app(ImportManager::class)->template(app(FullUsersTable::class));
    $contents = file_get_contents($path);

    expect($contents)->toContain('Name')
        ->and($contents)->toContain('Department')
        ->and($contents)->toContain('active | inactive | pending');

    @unlink($path);
});

it('analyses an uploaded file and suggests a mapping', function (): void {
    Storage::fake('local');

    $csv = "Name,Email,Department\nAda,ada@example.com,IT\n";
    $file = UploadedFile::fake()->createWithContent('people.csv', $csv);

    $response = $this->post(route('dynamic-table.import.analyze'), [
        'table' => 'full_users',
        'file' => $file,
    ])->assertOk();

    expect($response->json('headings'))->toBe(['Name', 'Email', 'Department'])
        ->and($response->json('mapping'))->toBe(['name', 'email', 'department__name'])
        ->and($response->json('preview.0.0'))->toBe('Ada')
        ->and($response->json('token'))->not->toBeEmpty();
});

it('imports rows, resolving relationships by their label', function (): void {
    Storage::fake('local');

    $csv = "Name,Email,Department,Status\nAda,ada@example.com,IT,active\nGrace,grace@example.com,HR,pending\n";
    $file = UploadedFile::fake()->createWithContent('people.csv', $csv);

    $analysis = $this->post(route('dynamic-table.import.analyze'), [
        'table' => 'full_users',
        'file' => $file,
    ])->json();

    $this->postJson(route('dynamic-table.import'), [
        'table' => 'full_users',
        'file' => $analysis['file'],
        'token' => $analysis['token'],
        'mapping' => $analysis['mapping'],
        'mode' => 'create',
    ])
        ->assertOk()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('failed', 0);

    $ada = User::where('email', 'ada@example.com')->first();

    expect($ada)->not->toBeNull()
        ->and($ada->department_id)->toBe(Department::where('name', 'IT')->value('id'));
});

it('reports per-row validation errors without losing the good rows', function (): void {
    Storage::fake('local');

    $csv = "Name,Email\nAda,ada@example.com\n,broken\n";
    $file = UploadedFile::fake()->createWithContent('people.csv', $csv);

    $analysis = $this->post(route('dynamic-table.import.analyze'), [
        'table' => 'full_users',
        'file' => $file,
    ])->json();

    $response = $this->postJson(route('dynamic-table.import'), [
        'table' => 'full_users',
        'file' => $analysis['file'],
        'token' => $analysis['token'],
        'mapping' => $analysis['mapping'],
        'mode' => 'create',
    ])->assertOk();

    expect($response->json('created'))->toBe(1)
        ->and($response->json('failed'))->toBe(1)
        ->and($response->json('errors.0.line'))->toBe(3)
        ->and($response->json('report'))->toContain('import-errors');
});

it('rejects an import whose file token does not match', function (): void {
    Storage::fake('local');

    $this->postJson(route('dynamic-table.import'), [
        'table' => 'full_users',
        'file' => 'dynamic-table/imports/../../../.env',
        'token' => 'forged',
        'mapping' => [],
    ])->assertForbidden();
});

it('queues a large import', function (): void {
    Queue::fake();
    Storage::fake('local');
    config()->set('dynamic-table.excel.queue_threshold', 1);

    $csv = "Name,Email\nAda,ada@example.com\nGrace,grace@example.com\n";
    $file = UploadedFile::fake()->createWithContent('people.csv', $csv);

    $analysis = $this->post(route('dynamic-table.import.analyze'), [
        'table' => 'full_users',
        'file' => $file,
    ])->json();

    $this->postJson(route('dynamic-table.import'), [
        'table' => 'full_users',
        'file' => $analysis['file'],
        'token' => $analysis['token'],
        'mapping' => $analysis['mapping'],
    ])->assertStatus(202)->assertJsonPath('queued', true);

    Queue::assertPushed(ImportJob::class);
});

/*
 * XLSX.
 *
 * The writers and readers are real shipped code but neither spreadsheet
 * library is a dependency of the package, so these run only where one is
 * installed — openspout is in require-dev, so that is everywhere the suite
 * normally runs.
 */
it('offers xlsx alongside csv when a spreadsheet library is installed', function (): void {
    expect(app(ExportManager::class)->supportedFormats())->toBe(['csv', 'xlsx']);
})->skip(fn (): bool => ! XlsxWriter::isAvailable(), 'Needs openspout or PhpSpreadsheet.');

it('declines xlsx when the adapter is set to csv, however it is installed', function (): void {
    config()->set('dynamic-table.excel.adapter', 'csv');

    expect(app(ExportManager::class)->supportedFormats())->toBe(['csv']);
})->skip(fn (): bool => ! XlsxWriter::isAvailable(), 'Needs openspout or PhpSpreadsheet.');

it('exports an xlsx a spreadsheet reader can read back', function (): void {
    $response = $this->post(route('dynamic-table.export'), [
        'table' => 'full_users',
        'scope' => 'view',
        'format' => 'xlsx',
        'state' => [
            'columns' => ['name', 'department__name'],
            'sort' => [['field' => 'name', 'direction' => 'asc']],
        ],
    ]);

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml');

    // Round-trip it: an xlsx that only the writer understands is not an xlsx.
    $file = tempnam(sys_get_temp_dir(), 'dt').'.xlsx';
    file_put_contents($file, streamOf($response));

    $reader = new XlsxReader;

    expect($reader->headings($file))->toBe(['Name', 'Department']);

    $rows = iterator_to_array($reader->rows($file));

    expect($rows)->toHaveCount(12)
        ->and($rows[0][0])->toBe('User 01')
        ->and($rows[0][1])->toBe('IT');

    @unlink($file);
})->skip(fn (): bool => ! XlsxWriter::isAvailable(), 'Needs openspout or PhpSpreadsheet.');

it('imports an xlsx through the same mapping as a csv', function (): void {
    Storage::fake('local');

    // Write a real workbook rather than a fixture blob, so the test says what
    // is in it and cannot rot against a library upgrade.
    $source = tempnam(sys_get_temp_dir(), 'dt').'.xlsx';
    $writer = new XlsxWriter;
    $writer->open($source);
    $writer->writeHeadings(['Name', 'Email', 'Department']);
    $writer->writeRow(['Ada', 'ada@example.com', 'IT']);
    $writer->writeRow(['Grace', 'grace@example.com', 'HR']);
    $writer->close();

    $file = new UploadedFile($source, 'people.xlsx', null, null, true);

    $analysis = $this->post(route('dynamic-table.import.analyze'), [
        'table' => 'full_users',
        'file' => $file,
    ])->assertOk()->json();

    expect($analysis['headings'])->toBe(['Name', 'Email', 'Department'])
        ->and($analysis['mapping'])->toBe(['name', 'email', 'department__name']);

    $this->postJson(route('dynamic-table.import'), [
        'table' => 'full_users',
        'file' => $analysis['file'],
        'token' => $analysis['token'],
        'mapping' => $analysis['mapping'],
        'mode' => 'create',
    ])
        ->assertOk()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('failed', 0);

    expect(User::where('email', 'ada@example.com')->first()?->department?->name)->toBe('IT');

    @unlink($source);
})->skip(fn (): bool => ! XlsxWriter::isAvailable(), 'Needs openspout or PhpSpreadsheet.');

it('builds an xlsx import template', function (): void {
    $response = $this->post(route('dynamic-table.template'), [
        'table' => 'full_users',
        'format' => 'xlsx',
    ]);

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('spreadsheetml');
})->skip(fn (): bool => ! XlsxWriter::isAvailable(), 'Needs openspout or PhpSpreadsheet.');

/*
 * The import error report.
 *
 * The rejected rows are written to a CSV on the transfer disk. It is reachable
 * only with an HMAC of its own key, which the import reply hands to whoever
 * ran the import — the same mechanism that protects the uploaded file.
 */
function failingImport(string $tableKey = 'full_users'): array
{
    $csv = "Name,Email\nAda,ada@example.com\n,broken\n";
    $file = UploadedFile::fake()->createWithContent('people.csv', $csv);

    $analysis = test()->post(route('dynamic-table.import.analyze'), [
        'table' => $tableKey,
        'file' => $file,
    ])->json();

    return test()->postJson(route('dynamic-table.import'), [
        'table' => $tableKey,
        'file' => $analysis['file'],
        'token' => $analysis['token'],
        'mapping' => $analysis['mapping'],
        'mode' => 'create',
    ])->assertOk()->json();
}

it('hands back a signed, downloadable error report', function (): void {
    Storage::fake('local');

    $summary = failingImport();

    expect($summary['report'])->toStartWith(ImportManager::reportDirectory().'/')
        ->and($summary['reportToken'])->not->toBeEmpty();

    $response = $this->post(route('dynamic-table.import.errors'), [
        'table' => 'full_users',
        'report' => $summary['report'],
        'token' => $summary['reportToken'],
    ])->assertOk();

    $csv = streamOf($response);

    // Heading plus the one rejected row, naming the field and the reason.
    expect($csv)->toContain('Line,Field,Error,Value')
        ->and($csv)->toContain('name');
});

it('refuses an error report whose token does not match', function (): void {
    Storage::fake('local');

    $summary = failingImport();

    $this->post(route('dynamic-table.import.errors'), [
        'table' => 'full_users',
        'report' => $summary['report'],
        'token' => 'not-the-token',
    ])->assertForbidden();
});

it('refuses an error report key that points outside the report directory', function (): void {
    Storage::fake('local');

    failingImport();

    // Defence in depth: this token is correctly signed, so the HMAC gate lets
    // it through. It stands in for a future bug that signs a key it should
    // not have — the endpoint still refuses to read outside its own folder.
    $forged = 'dynamic-table/imports/secret.csv';

    $this->post(route('dynamic-table.import.errors'), [
        'table' => 'full_users',
        'report' => $forged,
        'token' => hash_hmac('sha256', 'full_users|'.$forged, (string) config('app.key')),
    ])->assertForbidden();
});

it('refuses an error report to a viewer who may not import', function (): void {
    Storage::fake('local');

    // Produced through the same table that will refuse it, so the feature is
    // on and the token matches — the ability is the only thing left to deny.
    $summary = failingImport('no_import_users');

    config()->set('dynamic-table.testing.import', false);

    $this->post(route('dynamic-table.import.errors'), [
        'table' => 'no_import_users',
        'report' => $summary['report'],
        'token' => $summary['reportToken'],
    ])->assertForbidden();
});

it('refuses an error report fetched through a different table', function (): void {
    Storage::fake('local');

    $summary = failingImport('full_users');

    // no_import_users has the feature and allows import, so it clears every
    // gate but the one that matters: the token is signed for full_users.
    $this->post(route('dynamic-table.import.errors'), [
        'table' => 'no_import_users',
        'report' => $summary['report'],
        'token' => $summary['reportToken'],
    ])->assertForbidden();
});

it('answers 404 for a report that has been cleaned up', function (): void {
    Storage::fake('local');

    $summary = failingImport();

    Storage::disk()->delete($summary['report']);

    $this->post(route('dynamic-table.import.errors'), [
        'table' => 'full_users',
        'report' => $summary['report'],
        'token' => $summary['reportToken'],
    ])->assertNotFound();
});

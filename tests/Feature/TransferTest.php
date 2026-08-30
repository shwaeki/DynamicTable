<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Shwaeki\DynamicTable\Modules\Export\ExportJob;
use Shwaeki\DynamicTable\Modules\Import\ImportJob;
use Shwaeki\DynamicTable\Modules\Import\ImportManager;
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

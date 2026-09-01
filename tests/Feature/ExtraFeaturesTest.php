<?php

use Illuminate\Support\Facades\Event;
use Shwaeki\DynamicTable\Events\BulkActionExecuted;
use Shwaeki\DynamicTable\Events\RowCreated;
use Shwaeki\DynamicTable\Support\TablePayload;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Tests\Fixtures\ExtrasUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\User;

beforeEach(fn () => seedUsers());

/* ------------------------------------------------------------------ */
/* Toolbar actions */
/* ------------------------------------------------------------------ */

it('runs a toolbar action and returns its message', function (): void {
    $this->postJson(route('dynamic-table.toolbar-action'), [
        'table' => 'extras_users',
        'action' => 'ping',
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'pong');
});

it('validates the inputs a toolbar action declares', function (): void {
    $this->postJson(route('dynamic-table.toolbar-action'), [
        'table' => 'extras_users',
        'action' => 'rename-everyone',
        'input' => ['suffix' => ''],
    ])->assertStatus(422);

    $this->postJson(route('dynamic-table.toolbar-action'), [
        'table' => 'extras_users',
        'action' => 'rename-everyone',
        'input' => ['suffix' => 'jr'],
    ])->assertJsonPath('message', 'suffix:jr');
});

it('refuses a toolbar action that is not available to the viewer', function (): void {
    $this->postJson(route('dynamic-table.toolbar-action'), [
        'table' => 'extras_users',
        'action' => 'forbidden',
    ])->assertNotFound();
});

it('refuses to run a toolbar action that is a link', function (): void {
    $this->postJson(route('dynamic-table.toolbar-action'), [
        'table' => 'extras_users',
        'action' => 'handbook',
    ])->assertStatus(422);
});

it('does not expose toolbar actions on a table without the feature', function (): void {
    $this->postJson(route('dynamic-table.toolbar-action'), [
        'table' => 'users',
        'action' => 'ping',
    ])->assertStatus(403);
});

/* ------------------------------------------------------------------ */
/* Inline create */
/* ------------------------------------------------------------------ */

it('creates a record from the blank row and applies the defaults', function (): void {
    Event::fake([RowCreated::class]);

    $this->postJson(route('dynamic-table.create'), [
        'table' => 'extras_users',
        'fields' => ['name' => 'Created', 'email' => 'created@example.com', 'salary' => 5],
    ])
        ->assertStatus(201)
        ->assertJsonPath('ok', true)
        ->assertJsonPath('row.c.name', 'Created');

    $user = User::where('email', 'created@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->level)->toBe(3)
        ->and($user->is_active)->toBeTrue();

    Event::assertDispatched(RowCreated::class);
});

it('validates a created record with the table rules', function (): void {
    $this->postJson(route('dynamic-table.create'), [
        'table' => 'extras_users',
        'fields' => ['name' => '', 'email' => 'not-an-email'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonStructure(['errors' => ['name', 'email']]);

    expect(User::where('email', 'not-an-email')->exists())->toBeFalse();
});

it('rejects creating on a table without the create feature', function (): void {
    $this->postJson(route('dynamic-table.create'), [
        'table' => 'full_users',
        'fields' => ['name' => 'Nope'],
    ])->assertStatus(403);
});

/* ------------------------------------------------------------------ */
/* Bulk edit */
/* ------------------------------------------------------------------ */

it('applies the same values to every selected record', function (): void {
    Event::fake([BulkActionExecuted::class]);

    $ids = User::orderBy('id')->limit(3)->pluck('id')->all();

    $this->postJson(route('dynamic-table.bulk-edit'), [
        'table' => 'extras_users',
        'fields' => ['status' => 'pending'],
        'state' => ['selection' => ['mode' => 'include', 'ids' => $ids]],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('updated', 3);

    expect(User::whereIn('id', $ids)->where('status', 'pending')->count())->toBe(3)
        ->and(User::whereNotIn('id', $ids)->where('status', 'pending')->count())->toBe(0);

    Event::assertDispatched(BulkActionExecuted::class);
});

it('writes only the fields that were sent', function (): void {
    $user = User::orderBy('id')->first();
    $salary = (float) $user->salary;

    $this->postJson(route('dynamic-table.bulk-edit'), [
        'table' => 'extras_users',
        'fields' => ['status' => 'pending'],
        'state' => ['selection' => ['mode' => 'include', 'ids' => [$user->id]]],
    ])->assertOk();

    expect((float) $user->fresh()->salary)->toBe($salary);
});

it('validates bulk edit values once, before touching anything', function (): void {
    $ids = User::orderBy('id')->limit(2)->pluck('id')->all();

    $this->postJson(route('dynamic-table.bulk-edit'), [
        'table' => 'extras_users',
        'fields' => ['salary' => -1],
        'state' => ['selection' => ['mode' => 'include', 'ids' => $ids]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonStructure(['errors' => ['salary']]);

    expect(User::where('salary', -1)->count())->toBe(0);
});

it('refuses a bulk edit with no selection', function (): void {
    $this->postJson(route('dynamic-table.bulk-edit'), [
        'table' => 'extras_users',
        'fields' => ['status' => 'pending'],
    ])->assertStatus(422);
});

/* ------------------------------------------------------------------ */
/* Row detail */
/* ------------------------------------------------------------------ */

it('returns the detail panel for one row', function (): void {
    $user = User::first();

    $this->postJson(route('dynamic-table.row-detail'), [
        'table' => 'extras_users',
        'id' => $user->id,
    ])
        ->assertOk()
        ->assertJsonPath('html', '<p data-detail>'.e($user->email).'</p>');
});

it('cannot read a detail for a row outside the table query', function (): void {
    $user = User::first();
    $user->delete();

    $this->postJson(route('dynamic-table.row-detail'), [
        'table' => 'extras_users',
        'id' => $user->id,
    ])->assertNotFound();
});

it('rejects row detail on a table without the feature', function (): void {
    $this->postJson(route('dynamic-table.row-detail'), [
        'table' => 'full_users',
        'id' => User::value('id'),
    ])->assertStatus(403);
});

/* ------------------------------------------------------------------ */
/* Facets */
/* ------------------------------------------------------------------ */

it('counts how many rows each filter value would keep', function (): void {
    $response = $this->postJson(route('dynamic-table.options'), [
        'table' => 'extras_users',
        'field' => 'status',
    ])->assertOk();

    $counts = collect($response->json('options'))->pluck('count', 'value');

    expect((int) $counts['active'])->toBe(User::where('status', 'active')->count())
        ->and((int) $counts['inactive'])->toBe(User::where('status', 'inactive')->count());
});

it('excludes conditions on the same column, so the other values are not all zero', function (): void {
    $response = $this->postJson(route('dynamic-table.options'), [
        'table' => 'extras_users',
        'field' => 'status',
        'state' => [
            'filters' => [
                'logic' => 'and',
                'conditions' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']],
            ],
        ],
    ])->assertOk();

    $counts = collect($response->json('options'))->pluck('count', 'value');

    expect((int) $counts['inactive'])->toBe(User::where('status', 'inactive')->count());
});

it('does not count for a column that did not opt in', function (): void {
    $this->postJson(route('dynamic-table.options'), [
        'table' => 'extras_users',
        'field' => 'is_active',
    ])
        ->assertOk()
        ->assertJsonMissingPath('options.0.count');
});

/* ------------------------------------------------------------------ */
/* Payload */
/* ------------------------------------------------------------------ */

it('tells the browser what is sticky, faceted and editable', function (): void {
    $table = app(ExtrasUsersTable::class);
    $payload = app(TablePayload::class)->boot($table, TableState::fromArray([], $table));

    expect($payload['sticky'])->toBe(['name'])
        ->and($payload['stickyActions'])->toBeTrue()
        ->and($payload['filterCounts'])->toBe(['status'])
        ->and($payload['editableColumns'])->toContain('name')
        ->and($payload['toolbarActions'])->toHaveCount(3)   // the invisible one is absent
        ->and($payload['endpoints'])->toHaveKeys(['create', 'bulkEdit', 'rowDetail', 'toolbarAction']);
});

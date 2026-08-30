<?php

use Illuminate\Support\Facades\Event;
use Shwaeki\DynamicTable\Events\BulkActionExecuted;
use Shwaeki\DynamicTable\Tests\Fixtures\User;

beforeEach(fn () => seedUsers());

it('runs an action against an explicit selection', function (): void {
    Event::fake([BulkActionExecuted::class]);

    $ids = User::query()->where('is_active', false)->pluck('id')->all();

    $this->postJson(route('dynamic-table.action'), [
        'table' => 'full_users',
        'action' => 'activate',
        'state' => ['selection' => ['mode' => 'include', 'ids' => $ids]],
    ])
        ->assertOk()
        ->assertJsonPath('affected', 4);

    expect(User::where('is_active', false)->count())->toBe(0);

    Event::assertDispatched(BulkActionExecuted::class);
});

it('respects the active filters when selecting everything', function (): void {
    $this->postJson(route('dynamic-table.action'), [
        'table' => 'full_users',
        'action' => 'delete',
        'state' => [
            'selection' => ['mode' => 'exclude', 'ids' => []],
            'filters' => [['field' => 'department.name', 'operator' => 'equals', 'value' => 'IT']],
        ],
    ])->assertOk();

    expect(User::count())->toBe(8)
        ->and(User::withTrashed()->count())->toBe(12);
});

it('honours the exclusion list', function (): void {
    $keep = User::query()->limit(2)->pluck('id')->all();

    $this->postJson(route('dynamic-table.action'), [
        'table' => 'full_users',
        'action' => 'delete',
        'state' => ['selection' => ['mode' => 'exclude', 'ids' => $keep]],
    ])->assertOk();

    expect(User::count())->toBe(2)
        ->and(User::pluck('id')->all())->toBe($keep);
});

it('refuses an unknown action', function (): void {
    $this->postJson(route('dynamic-table.action'), [
        'table' => 'full_users',
        'action' => 'drop_database',
        'state' => ['selection' => ['mode' => 'include', 'ids' => [1]]],
    ])->assertNotFound();
});

it('refuses to run with an empty selection', function (): void {
    $this->postJson(route('dynamic-table.action'), [
        'table' => 'full_users',
        'action' => 'activate',
        'state' => ['selection' => ['mode' => 'include', 'ids' => []]],
    ])->assertStatus(422);
});

it('refuses actions on a table without the feature', function (): void {
    $this->postJson(route('dynamic-table.action'), [
        'table' => 'users',
        'action' => 'activate',
        'state' => ['selection' => ['mode' => 'include', 'ids' => [1]]],
    ])->assertStatus(403);
});

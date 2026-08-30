<?php

use Illuminate\Support\Facades\Event;
use Shwaeki\DynamicTable\Events\RowUpdated;
use Shwaeki\DynamicTable\Tests\Fixtures\User;

beforeEach(fn () => seedUsers());

it('saves a single cell and returns the repainted row', function (): void {
    Event::fake([RowUpdated::class]);

    $user = User::first();

    $this->postJson(route('dynamic-table.edit'), [
        'table' => 'full_users',
        'changes' => [['id' => $user->id, 'field' => 'name', 'value' => 'Renamed']],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('rows.0.c.name', 'Renamed');

    expect($user->fresh()->name)->toBe('Renamed');

    Event::assertDispatched(RowUpdated::class);
});

it('validates using the rules declared on the table', function (): void {
    $user = User::first();

    $this->postJson(route('dynamic-table.edit'), [
        'table' => 'full_users',
        'changes' => [['id' => $user->id, 'field' => 'salary', 'value' => -5]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonStructure(['errors' => [$user->id => ['salary']]]);

    expect((float) $user->fresh()->salary)->toBe(1000.0);
});

it('derives validation rules from column metadata when none are declared', function (): void {
    $user = User::first();

    $this->postJson(route('dynamic-table.edit'), [
        'table' => 'full_users',
        'changes' => [['id' => $user->id, 'field' => 'email', 'value' => 'not-an-email']],
    ])->assertStatus(422);

    $this->postJson(route('dynamic-table.edit'), [
        'table' => 'full_users',
        'changes' => [['id' => $user->id, 'field' => 'status', 'value' => 'bogus']],
    ])->assertStatus(422);
});

it('saves many cells in one request', function (): void {
    $users = User::query()->limit(3)->get();

    $changes = $users->map(fn (User $user): array => [
        'id' => $user->id,
        'field' => 'name',
        'value' => 'Batch '.$user->id,
    ])->all();

    $this->postJson(route('dynamic-table.edit'), ['table' => 'full_users', 'changes' => $changes])
        ->assertOk()
        ->assertJsonCount(3, 'rows');

    expect($users->first()->fresh()->name)->toBe('Batch '.$users->first()->id);
});

it('refuses to edit a column that is not editable', function (): void {
    $user = User::first();

    $this->postJson(route('dynamic-table.edit'), [
        'table' => 'full_users',
        'changes' => [['id' => $user->id, 'field' => 'display_name', 'value' => 'hacked']],
    ])->assertStatus(422);
});

it('refuses to edit through a table where the feature is off', function (): void {
    $this->postJson(route('dynamic-table.edit'), [
        'table' => 'users',
        'changes' => [['id' => User::first()->id, 'field' => 'name', 'value' => 'nope']],
    ])->assertStatus(500);
});

it('cannot reach a record outside the table query scope', function (): void {
    // full_users has no extra scope, so use the id of a soft-deleted record
    // which the default (without-trashed) query excludes.
    $user = User::first();
    $user->delete();

    $this->postJson(route('dynamic-table.edit'), [
        'table' => 'full_users',
        'changes' => [['id' => $user->id, 'field' => 'name', 'value' => 'nope']],
    ])
        ->assertStatus(422)
        ->assertJsonPath("errors.{$user->id}._.0", __('dynamic-table::table.errors.not_found'));
});

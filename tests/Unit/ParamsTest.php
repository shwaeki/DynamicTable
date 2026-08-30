<?php

use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Tests\Fixtures\Department;
use Shwaeki\DynamicTable\Tests\Fixtures\ParamsUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\UsersTable;

beforeEach(function (): void {
    seedUsers();
    $this->engine = app(QueryEngine::class);
});

it('hands declared parameters to query()', function (): void {
    $table = app(ParamsUsersTable::class);
    $state = stateFor($table, ['params' => ['status' => 'inactive']]);

    expect($this->engine->build($table, $state)->count())->toBe(4)
        ->and($table->param('status'))->toBe('inactive');
});

it('applies a declared default when nothing is sent', function (): void {
    $table = app(ParamsUsersTable::class);
    $first = $this->engine->build($table, stateFor($table))->first();

    expect($table->param('sort_mode'))->toBe('newest')
        ->and($first->name)->toBe('User 12');
});

it('accepts a list of values', function (): void {
    $table = app(ParamsUsersTable::class);
    $ids = Department::whereIn('name', ['IT', 'HR'])->pluck('id')->all();
    $state = stateFor($table, ['params' => ['departments' => $ids]]);

    expect($state->params['departments'])->toBe(array_values($ids))
        ->and($this->engine->build($table, $state)->count())->toBe(8);
});

it('drops parameters the table never declared', function (): void {
    $table = app(ParamsUsersTable::class);
    $state = stateFor($table, ['params' => ['status' => 'active', 'secret' => 'x']]);

    expect($state->params)->toHaveKey('status')
        ->and($state->params)->not->toHaveKey('secret')
        ->and($table->param('secret'))->toBeNull();
});

it('ignores parameters on a table that declares none', function (): void {
    $table = app(UsersTable::class);

    expect(stateFor($table, ['params' => ['status' => 'active']])->params)->toBe([]);
});

it('echoes parameters back to the browser in the state snapshot', function (): void {
    $table = app(ParamsUsersTable::class);
    $state = stateFor($table, ['params' => ['status' => 'active', 'min_level' => 3]]);

    expect($state->toArray()['params'])->toBe([
        'status' => 'active',
        'min_level' => 3,
        'sort_mode' => 'newest',
    ]);
});

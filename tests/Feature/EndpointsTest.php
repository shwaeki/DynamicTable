<?php

use Shwaeki\DynamicTable\Support\AssetManager;
use Shwaeki\DynamicTable\Tests\Fixtures\User;

beforeEach(fn () => seedUsers());

it('returns a page of rows', function (): void {
    $this->postJson(route('dynamic-table.data'), [
        'table' => 'users',
        'state' => ['perPage' => 10, 'page' => 1],
    ])
        ->assertOk()
        ->assertJsonPath('data.total', 12)
        ->assertJsonPath('data.page', 1)
        ->assertJsonCount(10, 'data.rows');
});

it('applies search, sorting and filters from the request', function (): void {
    $response = $this->postJson(route('dynamic-table.data'), [
        'table' => 'full_users',
        'state' => [
            'search' => 'User 1',
            'sort' => [['field' => 'name', 'direction' => 'desc']],
            'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']],
        ],
    ])->assertOk();

    $rows = $response->json('data.rows');

    expect($rows[0]['c']['name'])->toBe('User 11');
});

it('rejects an unknown table key', function (): void {
    $this->postJson(route('dynamic-table.data'), ['table' => 'ghosts'])->assertNotFound();
});

it('rejects a class name used as a table key', function (): void {
    $this->postJson(route('dynamic-table.data'), [
        'table' => User::class,
    ])->assertStatus(400);
});

it('serves the field catalogue for the filter builder', function (): void {
    $response = $this->postJson(route('dynamic-table.fields'), ['table' => 'full_users'])->assertOk();

    $paths = collect($response->json('groups'))->flatMap(fn (array $group) => array_column($group['fields'], 'path'));

    expect($paths)->toContain('name', 'department.name')
        ->and($paths)->not->toContain('password')
        ->and($paths)->not->toContain('level');
});

it('serves enum options without touching the database', function (): void {
    $this->postJson(route('dynamic-table.options'), ['table' => 'full_users', 'field' => 'status'])
        ->assertOk()
        ->assertJsonPath('options.0.value', 'active')
        ->assertJsonPath('more', false);
});

it('serves paginated searchable relationship options', function (): void {
    $this->postJson(route('dynamic-table.options'), [
        'table' => 'full_users',
        'field' => 'department.name',
        'search' => 'I',
    ])
        ->assertOk()
        ->assertJsonPath('options.0.label', 'IT');
});

it('refuses options for a hidden field', function (): void {
    $this->postJson(route('dynamic-table.options'), ['table' => 'full_users', 'field' => 'level'])
        ->assertForbidden();
});

it('serves package assets with long lived cache headers', function (): void {
    $response = $this->get(app(AssetManager::class)->url('core.js'));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('javascript')
        ->and((int) $response->headers->getCacheControlDirective('max-age'))->toBe(31536000);
});

it('will not serve an arbitrary file through the asset route', function (): void {
    $this->get(route('dynamic-table.asset.legacy', ['file' => 'composer.json']))->assertNotFound();
});

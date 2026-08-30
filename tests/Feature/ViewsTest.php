<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Shwaeki\DynamicTable\Models\DynamicTableView;
use Shwaeki\DynamicTable\Support\TableRenderer;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;
use Shwaeki\DynamicTable\Views\ViewEngine;

beforeEach(function (): void {
    seedUsers();

    $this->actor = new class extends Authenticatable
    {
        protected $table = 'users';
    };

    $this->actor->id = 1;
    $this->actingAs($this->actor);
});

it('lists presets alongside saved views', function (): void {
    $this->postJson(route('dynamic-table.views.index'), ['table' => 'full_users'])
        ->assertOk()
        ->assertJsonPath('views.0.id', 'preset:active')
        ->assertJsonPath('views.0.name', 'Active users');
});

it('saves the current state as a private view', function (): void {
    $this->postJson(route('dynamic-table.views.store'), [
        'table' => 'full_users',
        'name' => 'My IT users',
        'state' => [
            'filters' => [['field' => 'department.name', 'operator' => 'equals', 'value' => 'IT']],
            'columns' => ['name', 'email'],
            'sort' => [['field' => 'name', 'direction' => 'desc']],
        ],
    ])->assertCreated();

    $view = DynamicTableView::first();

    expect($view->name)->toBe('My IT users')
        ->and($view->user_id)->toBe('1')
        ->and($view->is_system)->toBeFalse()
        ->and($view->configuration['columns'])->toBe(['name', 'email'])
        ->and($view->configuration['version'])->toBe(1)
        ->and($view->configuration)->not->toHaveKey('sql');
});

it('applies a saved view when the table boots', function (): void {
    DynamicTableView::create([
        'table_key' => 'full_users',
        'user_id' => '1',
        'name' => 'IT only',
        'is_default' => true,
        'configuration' => [
            'version' => 1,
            'columns' => ['name', 'email'],
            'filters' => [
                'logic' => 'and',
                'conditions' => [['field' => 'department.name', 'operator' => 'equals', 'value' => 'IT']],
            ],
        ],
    ]);

    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect(substr_count($html, 'data-dt-row='))->toBe(4);
});

it('ignores fields a saved view references that no longer exist', function (): void {
    DynamicTableView::create([
        'table_key' => 'full_users',
        'user_id' => '1',
        'name' => 'Stale',
        'is_default' => true,
        'configuration' => [
            'version' => 1,
            'columns' => ['name', 'gone_away'],
            'filters' => [
                'logic' => 'and',
                'conditions' => [['field' => 'department.old_name', 'operator' => 'equals', 'value' => 'IT']],
            ],
        ],
    ]);

    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect($html)->toContain('data-dt-row=')
        ->and(substr_count($html, 'data-dt-row='))->toBe(12);
});

it('will not let a user touch someone else’s view', function (): void {
    $other = DynamicTableView::create([
        'table_key' => 'full_users',
        'user_id' => '999',
        'name' => 'Not yours',
        'configuration' => ['version' => 1],
    ]);

    $this->postJson(route('dynamic-table.views.update', ['view' => $other->id]), [
        'table' => 'full_users',
        'name' => 'Stolen',
    ])->assertNotFound();

    $this->postJson(route('dynamic-table.views.destroy', ['view' => $other->id]), [
        'table' => 'full_users',
    ])->assertNotFound();

    expect($other->fresh()->name)->toBe('Not yours');
});

it('requires authorisation to create or modify a system view', function (): void {
    $this->postJson(route('dynamic-table.views.store'), [
        'table' => 'full_users',
        'name' => 'Everyone',
        'system' => true,
        'state' => [],
    ])->assertForbidden();

    Gate::define('manage-dynamic-table-system-views', fn (): bool => true);

    $this->postJson(route('dynamic-table.views.store'), [
        'table' => 'full_users',
        'name' => 'Everyone',
        'system' => true,
        'state' => [],
    ])->assertCreated();

    expect(DynamicTableView::where('is_system', true)->count())->toBe(1);
});

it('lets a user set and swap their default view', function (): void {
    $first = DynamicTableView::create([
        'table_key' => 'full_users', 'user_id' => '1', 'name' => 'A',
        'configuration' => ['version' => 1], 'is_default' => true,
    ]);

    $second = DynamicTableView::create([
        'table_key' => 'full_users', 'user_id' => '1', 'name' => 'B',
        'configuration' => ['version' => 1],
    ]);

    $this->postJson(route('dynamic-table.views.default', ['view' => $second->id]), ['table' => 'full_users'])
        ->assertOk();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

it('lets a user clear their default view again', function (): void {
    $view = DynamicTableView::create([
        'table_key' => 'full_users', 'user_id' => '1', 'name' => 'Mine',
        'configuration' => ['version' => 1], 'is_default' => true,
    ]);

    $this->postJson(route('dynamic-table.views.default', ['view' => $view->id]), [
        'table' => 'full_users',
        'default' => false,
    ])->assertOk();

    expect($view->fresh()->is_default)->toBeFalse();
});

it('returns the refreshed view list after changing the default', function (): void {
    $view = DynamicTableView::create([
        'table_key' => 'full_users', 'user_id' => '1', 'name' => 'Mine',
        'configuration' => ['version' => 1],
    ]);

    $response = $this->postJson(route('dynamic-table.views.default', ['view' => $view->id]), [
        'table' => 'full_users',
    ])->assertOk();

    $returned = collect($response->json('views'))->firstWhere('id', (string) $view->id);

    expect($returned['default'])->toBeTrue();
});

it('keeps the user default independent of the system default', function (): void {
    Gate::define('manage-dynamic-table-system-views', fn (): bool => true);

    $system = DynamicTableView::create([
        'table_key' => 'full_users', 'user_id' => null, 'name' => 'Everyone',
        'configuration' => ['version' => 1], 'is_system' => true, 'is_default' => true,
    ]);

    $mine = DynamicTableView::create([
        'table_key' => 'full_users', 'user_id' => '1', 'name' => 'Mine',
        'configuration' => ['version' => 1],
    ]);

    $this->postJson(route('dynamic-table.views.default', ['view' => $mine->id]), ['table' => 'full_users'])
        ->assertOk();

    // Setting a personal default must not disturb the shared one; the user's
    // simply wins at boot.
    expect($mine->fresh()->is_default)->toBeTrue()
        ->and($system->fresh()->is_default)->toBeTrue()
        ->and(app(ViewEngine::class)
            ->defaultFor(app(FullUsersTable::class))->getKey())
        ->toBe($mine->getKey());
});

it('does not offer views on a table without the feature', function (): void {
    $this->postJson(route('dynamic-table.views.index'), ['table' => 'users'])->assertStatus(500);
});

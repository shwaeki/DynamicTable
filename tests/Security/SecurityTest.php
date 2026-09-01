<?php

use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\TableRegistry;
use Shwaeki\DynamicTable\Support\TableRenderer;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\RestrictedUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\User;

beforeEach(function (): void {
    seedUsers();

    config()->set('dynamic-table.tables.register', array_merge(
        config('dynamic-table.tables.register'),
        ['restricted_users' => RestrictedUsersTable::class],
    ));

    app(TableRegistry::class)->flush();
});

it('never passes a client supplied sort straight to order by', function (): void {
    $response = $this->postJson(route('dynamic-table.data'), [
        'table' => 'full_users',
        'state' => ['sort' => [['field' => 'name) --', 'direction' => 'asc; DROP TABLE users']]],
    ])->assertOk();

    expect($response->json('state.sort'))->toBe([['field' => 'name', 'direction' => 'asc']])
        ->and(User::count())->toBe(12);
});

it('drops filters that name an unexposed column', function (): void {
    $response = $this->postJson(route('dynamic-table.data'), [
        'table' => 'full_users',
        'state' => [
            'filters' => [
                ['field' => 'password', 'operator' => 'contains', 'value' => 'a'],
                ['field' => 'level', 'operator' => 'equals', 'value' => 1],
            ],
        ],
    ])->assertOk();

    expect($response->json('data.total'))->toBe(12)
        ->and($response->json('state'))->not->toHaveKey('filters');
});

it('honours an explicit column allowlist everywhere', function (): void {
    $table = app(RestrictedUsersTable::class);

    expect(array_keys($table->resolvedColumns()))->toBe(['name', 'status']);

    $fields = $this->postJson(route('dynamic-table.fields'), ['table' => 'restricted_users'])->json('groups');
    $paths = collect($fields)->flatMap(fn (array $group) => array_column($group['fields'], 'path'))->all();

    expect($paths)->toBe(['name', 'status']);

    $response = $this->postJson(route('dynamic-table.data'), [
        'table' => 'restricted_users',
        'state' => ['filters' => [['field' => 'email', 'operator' => 'contains', 'value' => 'user1']]],
    ])->assertOk();

    // The email filter was dropped, so the scope alone decides the count.
    expect($response->json('data.total'))->toBe(4);
});

it('cannot widen a scoped query through the data endpoint', function (): void {
    $response = $this->postJson(route('dynamic-table.data'), [
        'table' => 'restricted_users',
        'state' => ['perPage' => 100],
    ])->assertOk();

    expect($response->json('data.total'))->toBe(4);
});

it('enforces the authorize hook for every mutating endpoint', function (): void {
    $user = User::first();

    $this->postJson(route('dynamic-table.edit'), [
        'table' => 'restricted_users',
        'changes' => [['id' => $user->id, 'field' => 'name', 'value' => 'x']],
    ])->assertStatus(422);

    $this->postJson(route('dynamic-table.export'), [
        'table' => 'restricted_users',
        'scope' => 'view',
    ])->assertForbidden();

    expect($user->fresh()->name)->toBe('User 01');
});

it('blocks a table the viewer may not see', function (): void {
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected ?string $tableKey = 'secret_users';

        public function authorize(string $ability, ?Model $record = null): ?bool
        {
            return false;
        }
    };

    $html = app(TableRenderer::class)->render($table)->toHtml();

    expect($html)->toContain('dynamic-table-denied')
        ->and($html)->not->toContain('data-dynamic-table-row');
});

it('escapes cell content rendered on the server', function (): void {
    User::first()->update(['name' => '<script>alert(1)</script>']);

    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('escapes the boot payload so it cannot break out of its script tag', function (): void {
    User::first()->update(['name' => '</script><script>alert(1)</script>']);

    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect(substr_count($html, '<script'))->toBe(2); // boot payload + core.js module tag
});

it('caps per-page so a request cannot ask for the whole table', function (): void {
    $response = $this->postJson(route('dynamic-table.data'), [
        'table' => 'full_users',
        'state' => ['perPage' => 100000],
    ])->assertOk();

    expect($response->json('data.perPage'))->toBe(25);
});

it('limits how many selected ids a request may carry', function (): void {
    $state = TableState::fromArray([
        'selection' => ['mode' => 'include', 'ids' => range(1, 10000)],
    ], app(FullUsersTable::class));

    expect($state->selection['ids'])->toHaveCount(5000);
});

it('rejects a table key that is not a plain identifier', function (): void {
    foreach (['../../etc/passwd', 'users;drop', str_repeat('a', 200)] as $key) {
        $this->postJson(route('dynamic-table.data'), ['table' => $key])->assertStatus(400);
    }
});

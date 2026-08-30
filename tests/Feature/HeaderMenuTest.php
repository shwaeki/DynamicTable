<?php

use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Support\TableRegistry;
use Shwaeki\DynamicTable\Support\TableRenderer;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\User;
use Shwaeki\DynamicTable\Tests\Fixtures\UsersTable;

beforeEach(fn () => seedUsers());

it('renders a header menu trigger for every column', function (): void {
    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect(substr_count($html, 'data-dt-header-menu='))
        ->toBe(count(app(FullUsersTable::class)->resolvedColumns()) - 1); // display_name is hidden
});

it('does not render a header menu when nothing in it would work', function (): void {
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected ?string $tableKey = 'bare_users';

        // No sorting, filters, grouping, resizing, reordering or picker.
        protected array $features = ['only', 'pagination'];
    };

    expect(app(TableRenderer::class)->render($table)->toHtml())
        ->not->toContain('data-dt-header-menu');
});

it('can be switched off with the feature flag', function (): void {
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected ?string $tableKey = 'no_menu_users';

        // Sorting and filtering stay on; only the header menu goes.
        protected array $features = ['-header_menu'];
    };

    $html = app(TableRenderer::class)->render($table)->toHtml();

    expect($html)->not->toContain('data-dt-header-menu')
        // The rest of the table is untouched.
        ->and($html)->toContain('data-dt-sort');
});

it('groups by ordering in the database rather than in PHP', function (): void {
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected ?string $tableKey = 'grouped_users';

        protected array $features = ['grouping'];

        protected array $defaultSort = ['name' => 'asc'];
    };

    $state = TableState::fromArray(['group' => 'status'], $table);
    $query = app(QueryEngine::class)->build($table, $state);

    expect($state->group)->toBe('status')
        // The group column leads the ORDER BY, the requested sort follows.
        ->and(strtolower($query->toSql()))->toContain('order by "users"."status" asc, "users"."name" asc');

    $statuses = $query->get()->pluck('status')->map(fn ($status) => $status->value)->all();

    expect($statuses)->toBe(array_merge(
        array_fill(0, 8, 'active'),
        array_fill(0, 4, 'inactive'),
    ));
});

it('refuses to group by a computed column', function (): void {
    $state = TableState::fromArray(['group' => 'display_name'], app(FullUsersTable::class));

    expect($state->group)->toBeNull();
});

it('ignores grouping when the feature is off', function (): void {
    $state = TableState::fromArray(['group' => 'status'], app(UsersTable::class));

    expect($state->group)->toBeNull();
});

it('finds a table added since the discovery cache was written', function (): void {
    $registry = app(TableRegistry::class);

    // Prime the map without the table, the way a stale cache would.
    config()->set('dynamic-table.tables.register', []);
    $registry->flush();

    expect($registry->all())->toBe([]);

    config()->set('dynamic-table.tables.register', ['full_users' => FullUsersTable::class]);

    // Resolving must rescan rather than 404 on a table the page can render.
    expect($registry->classFor('full_users'))->toBe(FullUsersTable::class);
});

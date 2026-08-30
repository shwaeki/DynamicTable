<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Query\RowFormatter;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\User;
use Shwaeki\DynamicTable\Tests\Fixtures\UsersTable;

beforeEach(function (): void {
    seedUsers();
    $this->engine = app(QueryEngine::class);
});

it('paginates on the server', function (): void {
    $table = app(UsersTable::class);
    $paginator = $this->engine->paginate($table, stateFor($table, ['perPage' => 10, 'page' => 2]));

    expect($paginator->total())->toBe(12)
        ->and($paginator->count())->toBe(2)
        ->and($paginator->currentPage())->toBe(2);
});

it('searches only the configured columns', function (): void {
    $table = app(FullUsersTable::class);

    expect($this->engine->build($table, stateFor($table, ['search' => 'user1@']))->count())->toBe(1)
        ->and($this->engine->build($table, stateFor($table, ['search' => 'IT']))->count())->toBe(4);
});

it('sorts by a root column', function (): void {
    $table = app(UsersTable::class);

    $first = $this->engine
        ->build($table, stateFor($table, ['sort' => [['field' => 'name', 'direction' => 'desc']]]))
        ->first();

    expect($first->name)->toBe('User 12');
});

it('sorts by a relationship column with a subquery, not a join', function (): void {
    $table = app(FullUsersTable::class);

    $query = $this->engine->build($table, stateFor($table, [
        'sort' => [['field' => 'department__name', 'direction' => 'asc']],
    ]));

    $sql = strtolower($query->toSql());

    expect($sql)->not->toContain(' join ')
        ->and($sql)->toContain('order by (select')
        ->and($query->first()->department->name)->toBe('HR');
});

it('ignores an unknown or non-sortable sort field', function (): void {
    $table = app(FullUsersTable::class);
    $state = stateFor($table, ['sort' => [['field' => 'display_name', 'direction' => 'asc']]]);

    // display_name is a computed accessor, so it falls back to the table default.
    expect($state->sort)->toBe([['field' => 'name', 'direction' => 'asc']]);
});

it('loads relationship columns without N+1 queries', function (): void {
    $table = app(FullUsersTable::class);
    $state = stateFor($table, ['perPage' => 10]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $paginator = $this->engine->paginate($table, $state);
    $rows = app(RowFormatter::class)->rows(
        $paginator->items(),
        $this->engine->activeColumns($table, $state),
        $table,
    );

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Row estimate + count + page + one eager load per relationship
    // (department, role). What matters is that the number does not grow with
    // the page: see the page-size test in the performance suite.
    expect($queries)->toBeLessThanOrEqual(5)
        ->and($rows)->toHaveCount(10)
        ->and($rows[0]['c']['department__name'])->not->toBeNull();
});

it('never selects blocked columns', function (): void {
    $table = app(UsersTable::class);
    $sql = $this->engine->build($table, stateFor($table))->toSql();

    expect($sql)->not->toContain('password')
        ->and($sql)->not->toContain('remember_token');
});

it('applies the developer query and scopes before anything else', function (): void {
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected ?string $tableKey = 'scoped_users';

        public function query(Builder $query): Builder
        {
            return $query->where('is_active', true);
        }
    };

    expect($this->engine->build($table, stateFor($table))->count())->toBe(8);
});

it('excludes soft deleted rows unless asked', function (): void {
    User::query()->limit(3)->get()->each->delete();

    $table = app(FullUsersTable::class);

    expect($this->engine->build($table, stateFor($table))->count())->toBe(9)
        ->and($this->engine->build($table, stateFor($table, ['trashed' => 'with']))->count())->toBe(12)
        ->and($this->engine->build($table, stateFor($table, ['trashed' => 'only']))->count())->toBe(3);
});

it('builds a selection query from ids plus the active filters', function (): void {
    $table = app(FullUsersTable::class);
    $ids = User::query()->limit(3)->pluck('id')->all();

    $state = stateFor($table, ['selection' => ['mode' => 'include', 'ids' => $ids]]);

    expect($this->engine->selectionQuery($table, $state)->count())->toBe(3);
});

it('treats select-all as an exclusion list intersected with the filters', function (): void {
    $table = app(FullUsersTable::class);
    $excluded = User::query()->limit(2)->pluck('id')->all();

    $state = stateFor($table, [
        'selection' => ['mode' => 'exclude', 'ids' => $excluded],
        'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']],
    ]);

    // 8 active users, minus any excluded ids that were themselves active.
    expect($this->engine->selectionQuery($table, $state)->count())->toBe(6);
});

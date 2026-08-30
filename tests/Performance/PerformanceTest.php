<?php

use Illuminate\Support\Facades\DB;
use Shwaeki\DynamicTable\Support\TablePayload;
use Shwaeki\DynamicTable\Support\TableRenderer;
use Shwaeki\DynamicTable\Tests\Fixtures\Department;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\Role;
use Shwaeki\DynamicTable\Tests\Fixtures\User;
use Shwaeki\DynamicTable\Tests\Fixtures\UsersTable;

/**
 * These are budget tests, not benchmarks: they assert the shape of the work
 * (query count, memory ceiling) rather than wall-clock time, so they stay
 * meaningful on any CI machine. See docs/performance.md for the real numbers.
 */
function seedMany(int $count): void
{
    $departments = collect(range(1, 20))->map(
        fn (int $index): Department => Department::create(['name' => 'Dept '.$index, 'code' => 'D'.$index]),
    );

    $roles = collect(range(1, 5))->map(
        fn (int $index): Role => Role::create(['name' => 'Role '.$index]),
    );

    $rows = [];
    $now = now();

    for ($index = 1; $index <= $count; $index++) {
        $rows[] = [
            'name' => 'Person '.$index,
            'email' => 'person'.$index.'@example.com',
            'password' => 'x',
            'status' => 'active',
            'is_active' => true,
            'salary' => 1000 + $index,
            'level' => ($index % 5) + 1,
            'department_id' => $departments[$index % 20]->id,
            'role_id' => $roles[$index % 5]->id,
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($rows) === 500) {
            User::insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        User::insert($rows);
    }
}

it('renders a page of 100 rows with relationship columns in a constant number of queries', function (): void {
    seedMany(1000);

    $table = app(FullUsersTable::class);
    $state = stateFor($table, ['perPage' => 100]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    app(TablePayload::class)->data($table, $state);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // 1 count + 1 page + 1 per eager-loaded relation (department, role).
    expect($queries)->toHaveCount(4);
});

it('does not grow its query count with the page size', function (): void {
    seedMany(500);

    $table = app(FullUsersTable::class);

    $count = function (int $perPage) use ($table): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(TablePayload::class)->data($table, stateFor($table, ['perPage' => $perPage]));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // First call also warms the metadata engine (schema introspection), which
    // is cached in real applications; measure the steady state.
    $count(10);

    expect($count(10))->toBe($count(100));
});

it('keeps memory flat while exporting a large result set', function (): void {
    seedMany(5000);

    $table = app(FullUsersTable::class);
    $state = stateFor($table, ['columns' => ['name', 'email', 'department__name']]);

    $before = memory_get_usage(true);

    $response = $this->post(route('dynamic-table.export'), [
        'table' => 'full_users',
        'scope' => 'view',
        'format' => 'csv',
        'state' => ['columns' => ['name', 'email', 'department__name']],
    ]);

    ob_start();
    $response->baseResponse->sendContent();
    $csv = ob_get_clean();

    $growth = (memory_get_usage(true) - $before) / 1048576;

    expect(substr_count($csv, "\n"))->toBeGreaterThan(5000)
        ->and($growth)->toBeLessThan(48.0);
})->skip(PHP_OS_FAMILY === 'Windows' && getenv('CI') !== 'true', 'Memory ceilings are measured in CI.');

it('never issues an unbounded select for a plain table', function (): void {
    seedMany(200);

    $table = app(UsersTable::class);

    DB::enableQueryLog();
    DB::flushQueryLog();

    app(TablePayload::class)->data($table, stateFor($table));

    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
    DB::disableQueryLog();

    expect($sql)->toContain('limit')
        ->and($sql)->not->toContain('select * from "users" where 1 = 1');
});

it('boots a table without touching the views table when the feature is off', function (): void {
    seedMany(50);

    DB::enableQueryLog();
    DB::flushQueryLog();

    app(TableRenderer::class)->render(UsersTable::class);

    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
    DB::disableQueryLog();

    expect($sql)->not->toContain('dynamic_table_views');
});

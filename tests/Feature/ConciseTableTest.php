<?php

use Illuminate\Support\Facades\Route;
use Shwaeki\DynamicTable\Columns\Badge;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Query\RowFormatter;
use Shwaeki\DynamicTable\Support\TableRenderer;
use Shwaeki\DynamicTable\Support\Theme;
use Shwaeki\DynamicTable\Tests\Fixtures\ConciseUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\Department;
use Shwaeki\DynamicTable\Tests\Fixtures\User;

beforeEach(function (): void {
    seedUsers();
    Route::get('/users/{user}/edit', fn () => '')->name('users.edit');

    $this->table = app(ConciseUsersTable::class);
    $this->engine = app(QueryEngine::class);
});

/** @return array<string, mixed> the cells of one row, by column key */
function cellsFor(object $table, User $user): array
{
    $user->load('department', 'role');

    return app(RowFormatter::class)->row($user, array_values($table->resolvedColumns()), $table)['c'];
}

it('draws a mapped value as a badge, label and all', function (): void {
    $cells = cellsFor($this->table, User::where('name', 'User 01')->first());

    // The theme owns the badge class; the tone is the package's own modifier.
    expect($cells['status'])
        ->toContain('dynamic-table-badge-success')
        ->toContain('>Active</span>')
        ->and($this->table->column('status')->raw)->toBeTrue();
});

it('badges a boolean with the words the map gives it', function (): void {
    expect(cellsFor($this->table, User::where('name', 'User 03')->first())['is_active'])
        ->toContain('dynamic-table-badge-danger')
        ->toContain('>Off</span>')
        ->and(cellsFor($this->table, User::where('name', 'User 01')->first())['is_active'])
        ->toContain('dynamic-table-badge-success')
        ->toContain('>On</span>');
});

it('leaves a row alone when the badge closure returns nothing', function (): void {
    // level is ($index % 5) + 1, so User 03 is 4 and User 01 is 2.
    expect(cellsFor($this->table, User::where('name', 'User 03')->first())['level'])
        ->toContain('dynamic-table-badge-warning')
        ->toContain('>High</span>')
        ->and(cellsFor($this->table, User::where('name', 'User 01')->first())['level'])
        ->toBe('2');
});

it('says what a blank cell means when the column was given the words', function (): void {
    $user = User::create(['name' => 'User 99', 'email' => 'user99@example.com', 'status' => 'active']);

    expect(cellsFor($this->table, $user)['department__name'])->toBe('Unassigned');
});

it('escapes a label, so a badge can never carry markup out of the model', function (): void {
    expect(Badge::html('<b>x</b>', 'success'))
        ->toBe('<span class="dynamic-table-badge dynamic-table-badge-success">&lt;b&gt;x&lt;/b&gt;</span>')
        // A theme decides where its own tone goes.
        ->and(Badge::classes('badge badge-light-{tone}', 'danger'))->toBe('badge badge-light-danger')
        ->and(Badge::classes('badge badge-light-{tone}', null))->toBe('badge badge-light-neutral');
});

it('colours a value on its own when the column just says "badges"', function (): void {
    expect(Badge::tone('active'))->toBe('success')
        ->and(Badge::tone('overdue'))->toBe('danger')
        ->and(Badge::tone('pending'))->toBe('warning')
        ->and(Badge::tone('whatever'))->toBe('neutral');
});

it('binds a parameter to its own column, and to a column of another name', function (): void {
    $it = Department::where('name', 'IT')->first();

    expect($this->engine->build($this->table, stateFor($this->table, ['params' => ['status' => 'inactive']]))->count())->toBe(4)
        ->and($this->engine->build($this->table, stateFor($this->table, ['params' => ['department' => $it->id]]))->count())->toBe(4);
});

it('applies the operator a filter declares', function (): void {
    $count = fn (array $params): int => $this->engine
        ->build($this->table, stateFor($this->table, ['params' => $params]))
        ->count();

    expect($count(['name_like' => 'User 1']))->toBe(3)          // 1, 10, 11, 12 minus User 1 itself → 10, 11, 12
        ->and($count(['min_level' => 4]))->toBe(4)
        ->and($count(['levels' => [1, 2]]))->toBe(5)
        ->and($count(['role' => 'Admin']))->toBe(6)             // through the relation
        ->and($count(['email_domain' => 'example.com']))->toBe(12);
});

it('leaves the query untouched when a parameter did not arrive', function (): void {
    expect($this->engine->build($this->table, stateFor($this->table))->count())->toBe(12);
});

it('reads a date period, in every vocabulary it accepts', function (): void {
    $count = fn (array $params): int => $this->engine
        ->build($this->table, stateFor($this->table, ['params' => $params]))
        ->count();

    // Users joined one day apart, counting back from today.
    expect($count(['joined_period' => 'week']))->toBe(7)
        ->and($count(['joined_period' => 'today']))->toBe(0)
        ->and($count(['joined_period' => 'last_n_days:3']))->toBe(3)
        ->and($count([
            'joined_period' => 'custom',
            'joined_from' => now()->subDays(3)->toDateString(),
            'joined_to' => now()->toDateString(),
        ]))->toBe(3);
});

it('declares the parameters its filters read, companions included', function (): void {
    expect(array_keys($this->table->declaredParams()))->toBe([
        'status', 'department', 'name_like', 'role', 'min_level', 'levels',
        'joined_period', 'joined_from', 'joined_to', 'email_domain',
    ]);
});

it('points a row action at a named route without a closure', function (): void {
    $html = app(TableRenderer::class)->render(ConciseUsersTable::class)->toHtml();

    expect($html)
        ->toContain('<i class="far fa-edit"></i>')
        ->toMatch('#href="http://localhost/users/\d+/edit"#');
});

it('starts a theme from one that already works, and puts the tone where it says', function (): void {
    config()->set('dynamic-table.themes.metronic', [
        'extends' => 'bootstrap',
        'badge' => 'badge badge-light-{tone}',
    ]);

    $classes = Theme::classes('metronic');

    expect($classes['search'])->toBe('form-control form-control-sm')     // inherited
        ->and($classes['badge'])->toBe('badge badge-light-{tone}')       // overridden
        ->and(Badge::html('Paid', 'success', $classes['badge']))
        ->toBe('<span class="badge badge-light-success">Paid</span>');
});

it('does not chase an extends cycle round for ever', function (): void {
    config()->set('dynamic-table.themes.a', ['extends' => 'b', 'badge' => 'from-a']);
    config()->set('dynamic-table.themes.b', ['extends' => 'a', 'search' => 'from-b']);

    expect(Theme::classes('a'))
        ->toHaveKey('badge', 'from-a')
        ->toHaveKey('search', 'from-b');
});

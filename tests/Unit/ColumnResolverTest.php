<?php

use Shwaeki\DynamicTable\Metadata\FieldType;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\UsersTable;

it('discovers a useful column set with no configuration', function (): void {
    $columns = app(UsersTable::class)->resolvedColumns();
    $keys = array_keys($columns);

    expect($keys)->toContain('name', 'email', 'status')
        ->and($keys)->not->toContain('id')
        ->and($keys)->not->toContain('updated_at')
        ->and($keys)->not->toContain('password');
});

it('replaces foreign keys with the related label column', function (): void {
    $keys = array_keys(app(UsersTable::class)->resolvedColumns());

    expect($keys)->toContain('department__name')
        ->and($keys)->toContain('role__name')
        ->and($keys)->not->toContain('department_id');
});

it('hides json columns by default but keeps them available', function (): void {
    $column = app(UsersTable::class)->column('settings');

    expect($column)->not->toBeNull()
        ->and($column->visible)->toBeFalse();
});

it('honours explicit column declarations, labels and formats', function (): void {
    $table = app(FullUsersTable::class);

    expect(array_keys($table->resolvedColumns()))->toBe([
        'name', 'email', 'department__name', 'role__name', 'status',
        'is_active', 'salary', 'joined_at', 'display_name',
    ])
        ->and($table->column('department__name')->label)->toBe('Department')
        ->and($table->column('salary')->format)->toBe('currency:USD')
        ->and($table->column('salary')->align)->toBe('end')
        ->and($table->column('display_name')->visible)->toBeFalse();
});

it('drops hidden columns entirely', function (): void {
    expect(app(FullUsersTable::class)->column('level'))->toBeNull();
});

it('derives sensible alignment and editability', function (): void {
    $table = app(FullUsersTable::class);

    expect($table->column('name')->editable)->toBeTrue()
        ->and($table->column('display_name')->editable)->toBeFalse()
        ->and($table->column('department__name')->editable)->toBeFalse()
        ->and($table->column('is_active')->defaultAlign())->toBe('center')
        ->and($table->column('salary')->type)->toBe(FieldType::Decimal);
});

it('derives a table key from the class name', function (): void {
    expect(app(UsersTable::class)->key())->toBe('users')
        ->and(app(FullUsersTable::class)->key())->toBe('full_users');
});

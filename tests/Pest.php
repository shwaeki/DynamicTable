<?php

use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Tests\Fixtures\Department;
use Shwaeki\DynamicTable\Tests\Fixtures\Role;
use Shwaeki\DynamicTable\Tests\Fixtures\User;
use Shwaeki\DynamicTable\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Unit', __DIR__.'/Feature', __DIR__.'/Security', __DIR__.'/Performance');

/**
 * Deterministic seed data: three departments, two roles and a predictable set
 * of users so assertions can name exact rows.
 */
function seedUsers(int $count = 12): void
{
    $departments = collect(['IT', 'HR', 'Sales'])->map(
        fn (string $name, int $index): Department => Department::create([
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 2)),
        ]),
    );

    $roles = collect(['Admin', 'Member'])->map(
        fn (string $name): Role => Role::create(['name' => $name]),
    );

    for ($index = 1; $index <= $count; $index++) {
        User::create([
            'name' => 'User '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'email' => 'user'.$index.'@example.com',
            'password' => 'secret',
            'status' => $index % 3 === 0 ? 'inactive' : 'active',
            'is_active' => $index % 3 !== 0,
            'salary' => 1000 * $index,
            'level' => ($index % 5) + 1,
            'department_id' => $departments[($index - 1) % 3]->id,
            'role_id' => $roles[($index - 1) % 2]->id,
            'joined_at' => now()->subDays($index),
        ]);
    }
}

/** @param array<string, mixed> $input */
function stateFor(object $table, array $input = []): TableState
{
    return TableState::fromArray($input, $table);
}

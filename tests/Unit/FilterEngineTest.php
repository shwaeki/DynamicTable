<?php

use Shwaeki\DynamicTable\Filters\FilterEngine;
use Shwaeki\DynamicTable\Query\QueryEngine;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;

beforeEach(function (): void {
    seedUsers();
    $this->table = app(FullUsersTable::class);
    $this->engine = app(FilterEngine::class);
});

function countWith(object $table, array $filters): int
{
    return app(QueryEngine::class)
        ->build($table, stateFor($table, ['filters' => $filters]))
        ->count();
}

it('applies a simple equality filter', function (): void {
    expect(countWith($this->table, [
        ['field' => 'status', 'operator' => 'equals', 'value' => 'inactive'],
    ]))->toBe(4);
});

it('applies contains with escaped wildcards', function (): void {
    expect(countWith($this->table, [
        ['field' => 'name', 'operator' => 'contains', 'value' => 'User 0'],
    ]))->toBe(9)
        ->and(countWith($this->table, [
            ['field' => 'name', 'operator' => 'contains', 'value' => '%'],
        ]))->toBe(0);
});

it('applies numeric comparisons and ranges', function (): void {
    expect(countWith($this->table, [
        ['field' => 'salary', 'operator' => 'greater_than', 'value' => 9000],
    ]))->toBe(3)
        ->and(countWith($this->table, [
            ['field' => 'salary', 'operator' => 'between', 'value' => [3000, 5000]],
        ]))->toBe(3);
});

it('applies relationship filters without duplicating rows', function (): void {
    expect(countWith($this->table, [
        ['field' => 'department.name', 'operator' => 'equals', 'value' => 'IT'],
    ]))->toBe(4);
});

it('treats a negative relationship filter as an exclusion', function (): void {
    expect(countWith($this->table, [
        ['field' => 'department.name', 'operator' => 'not_equals', 'value' => 'IT'],
    ]))->toBe(8);
});

it('combines conditions with AND', function (): void {
    expect(countWith($this->table, [
        'logic' => 'and',
        'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
            ['field' => 'department.name', 'operator' => 'equals', 'value' => 'IT'],
        ],
    ]))->toBe(4);
});

it('supports nested OR groups', function (): void {
    expect(countWith($this->table, [
        'logic' => 'and',
        'conditions' => [
            [
                'logic' => 'or',
                'conditions' => [
                    ['field' => 'department.name', 'operator' => 'equals', 'value' => 'IT'],
                    ['field' => 'department.name', 'operator' => 'equals', 'value' => 'HR'],
                ],
            ],
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ],
    ]))->toBe(8);
});

it('handles boolean and null checks', function (): void {
    expect(countWith($this->table, [
        ['field' => 'is_active', 'operator' => 'equals', 'value' => false],
    ]))->toBe(4)
        ->and(countWith($this->table, [
            ['field' => 'joined_at', 'operator' => 'is_empty'],
        ]))->toBe(0)
        ->and(countWith($this->table, [
            ['field' => 'joined_at', 'operator' => 'is_not_empty'],
        ]))->toBe(12);
});

it('resolves relative date operators', function (): void {
    expect(countWith($this->table, [
        ['field' => 'joined_at', 'operator' => 'last_n_days', 'value' => 5],
    ]))->toBe(5);
});

it('drops unknown fields instead of throwing', function (): void {
    $group = $this->engine->parse([
        ['field' => 'does_not_exist', 'operator' => 'equals', 'value' => 'x'],
        ['field' => 'name', 'operator' => 'equals', 'value' => 'User 01'],
    ], $this->table);

    expect($group->count())->toBe(1)
        ->and($this->engine->warnings())->toContain('does_not_exist');
});

it('rejects operators that do not belong to the field type', function (): void {
    $group = $this->engine->parse([
        ['field' => 'is_active', 'operator' => 'contains', 'value' => 'yes'],
    ], $this->table);

    expect($group->count())->toBe(0);
});

it('rejects values that cannot be coerced', function (): void {
    $group = $this->engine->parse([
        ['field' => 'salary', 'operator' => 'greater_than', 'value' => 'not-a-number'],
        ['field' => 'status', 'operator' => 'equals', 'value' => 'not-a-status'],
    ], $this->table);

    expect($group->count())->toBe(0);
});

it('caps the number of conditions', function (): void {
    config()->set('dynamic-table.security.max_filters', 3);

    $conditions = array_fill(0, 10, ['field' => 'name', 'operator' => 'contains', 'value' => 'User']);
    $group = $this->engine->parse($conditions, $this->table);

    expect($group->count())->toBe(3);
});

it('caps nesting depth', function (): void {
    config()->set('dynamic-table.security.max_filter_depth', 1);

    $group = $this->engine->parse([
        'logic' => 'and',
        'conditions' => [
            [
                'logic' => 'or',
                'conditions' => [
                    [
                        'logic' => 'and',
                        'conditions' => [
                            ['field' => 'name', 'operator' => 'contains', 'value' => 'User'],
                        ],
                    ],
                ],
            ],
        ],
    ], $this->table);

    expect($group->count())->toBe(0);
});

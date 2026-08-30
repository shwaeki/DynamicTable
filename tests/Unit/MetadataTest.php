<?php

use Shwaeki\DynamicTable\Metadata\FieldType;
use Shwaeki\DynamicTable\Metadata\MetadataEngine;
use Shwaeki\DynamicTable\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->metadata = app(MetadataEngine::class);
});

it('discovers database columns', function (): void {
    $meta = $this->metadata->for(User::class);

    expect($meta->table)->toBe('users')
        ->and($meta->keyName)->toBe('id')
        ->and($meta->fieldNames())->toContain('name', 'email', 'status', 'salary', 'created_at');
});

it('never exposes sensitive columns', function (): void {
    $meta = $this->metadata->for(User::class);

    expect($meta->fieldNames())
        ->not->toContain('password')
        ->not->toContain('remember_token');
});

it('detects types from casts and the schema', function (): void {
    $meta = $this->metadata->for(User::class);

    expect($meta->field('is_active')->type)->toBe(FieldType::Boolean)
        ->and($meta->field('salary')->type)->toBe(FieldType::Decimal)
        ->and($meta->field('level')->type)->toBe(FieldType::Integer)
        ->and($meta->field('settings')->type)->toBe(FieldType::Json)
        ->and($meta->field('joined_at')->type)->toBe(FieldType::DateTime)
        ->and($meta->field('email')->type)->toBe(FieldType::Email)
        ->and($meta->field('status')->type)->toBe(FieldType::Enum);
});

it('reads enum options from the cast', function (): void {
    $options = $this->metadata->for(User::class)->field('status')->options;

    expect(array_column($options, 'value'))->toBe(['active', 'inactive', 'pending']);
});

it('marks appended accessors as computed', function (): void {
    $field = $this->metadata->for(User::class)->field('display_name');

    expect($field)->not->toBeNull()
        ->and($field->computed)->toBeTrue()
        ->and($field->isQueryable())->toBeFalse()
        ->and($field->isSortable())->toBeFalse();
});

it('discovers relationships', function (): void {
    $meta = $this->metadata->for(User::class);

    expect($meta->relation('department')?->type)->toBe('BelongsTo')
        ->and($meta->relation('department')?->foreignKey)->toBe('department_id')
        ->and($meta->relation('role')?->isSingular())->toBeTrue();
});

it('resolves dotted relationship paths', function (): void {
    $field = $this->metadata->resolve(User::class, 'department.name');

    expect($field)->not->toBeNull()
        ->and($field->isRelational())->toBeTrue()
        ->and($field->relationKey())->toBe('department')
        ->and($field->label)->toBe('Department Name');
});

it('resolves a bare relation to its label column', function (): void {
    $field = $this->metadata->resolve(User::class, 'department');

    expect($field?->name)->toBe('name')
        ->and($field?->relationKey())->toBe('department');
});

it('rejects unknown and malformed paths', function (): void {
    expect($this->metadata->resolve(User::class, 'nope'))->toBeNull()
        ->and($this->metadata->resolve(User::class, 'department.nope'))->toBeNull()
        ->and($this->metadata->resolve(User::class, 'name; DROP TABLE users'))->toBeNull()
        ->and($this->metadata->resolve(User::class, ''))->toBeNull();
});

it('honours the relation depth limit', function (): void {
    config()->set('dynamic-table.security.max_relation_depth', 1);

    expect($this->metadata->resolve(User::class, 'department.manager.name'))->toBeNull();

    config()->set('dynamic-table.security.max_relation_depth', 3);

    expect($this->metadata->resolve(User::class, 'department.manager.name'))->not->toBeNull();
});

it('detects soft deletes', function (): void {
    expect($this->metadata->for(User::class)->usesSoftDeletes)->toBeTrue();
});

it('builds a grouped field tree for the filter builder', function (): void {
    $tree = $this->metadata->tree(User::class, 1);
    $keys = array_column($tree, 'key');

    expect($keys)->toContain('')
        ->and($keys)->toContain('department')
        ->and($keys)->toContain('role');
});

it('generates human labels', function (): void {
    expect($this->metadata->labelFor('created_at'))->toBe('Created At')
        ->and($this->metadata->labelFor('department.name'))->toBe('Department Name')
        ->and($this->metadata->labelFor('department_id'))->toBe('Department');
});

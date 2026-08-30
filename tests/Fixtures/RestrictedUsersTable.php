<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\DynamicTable;

/** An explicitly allowlisted, scoped, read-only table. */
class RestrictedUsersTable extends DynamicTable
{
    protected string $model = User::class;

    protected ?string $tableKey = 'restricted_users';

    protected array $features = ['inline_edit', 'bulk-actions', 'export'];

    protected array $allowedColumns = ['name', 'status'];

    public function query(Builder $query): Builder
    {
        return $query->where('department_id', 1);
    }

    public function authorize(string $ability, ?Model $record = null): ?bool
    {
        return in_array($ability, ['view'], true);
    }
}

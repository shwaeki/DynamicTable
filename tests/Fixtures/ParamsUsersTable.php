<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Shwaeki\DynamicTable\DynamicTable;

/** A table driven by controls of the application's own, outside the table. */
class ParamsUsersTable extends DynamicTable
{
    protected string $model = User::class;

    protected array $params = ['status', 'min_level', 'departments', 'sort_mode' => 'newest'];

    public function query(Builder $query): Builder
    {
        if ($status = $this->param('status')) {
            $query->where('status', $status);
        }

        if ($level = $this->param('min_level')) {
            $query->where('level', '>=', (int) $level);
        }

        if ($departments = $this->param('departments')) {
            $query->whereIn('department_id', (array) $departments);
        }

        return $query->orderBy('id', $this->param('sort_mode') === 'newest' ? 'desc' : 'asc');
    }
}

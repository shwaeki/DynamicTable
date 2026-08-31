<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Shwaeki\DynamicTable\Actions\RowAction;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * The same table an application would otherwise write by hand: parameters bound
 * to the query, badges and placeholders declared rather than rendered.
 */
class ConciseUsersTable extends DynamicTable
{
    protected string $model = User::class;

    protected ?string $tableKey = 'concise_users';

    protected array $features = [Feature::ROW_ACTIONS];

    protected array $defaultSort = ['name' => 'asc'];

    protected array $paramFilters = [
        'status',
        'department' => 'department_id',
        'name_like' => ['column' => 'name', 'operator' => 'contains'],
        'role' => ['column' => 'role.name'],
        'min_level' => ['column' => 'level', 'operator' => '>='],
        'levels' => ['column' => 'level', 'operator' => 'in'],
        'joined_period' => ['column' => 'joined_at', 'operator' => 'period'],
    ];

    /** A closure cannot live in a property default, so it comes from here. */
    public function paramFilters(): array
    {
        return [
            ...parent::paramFilters(),
            'email_domain' => fn (Builder $query, string $value) => $query->where('email', 'like', '%@'.$value),
        ];
    }

    protected function columns(): array
    {
        return [
            'name',
            'status' => ['badges' => ['active' => 'success', 'inactive' => 'danger']],
            'is_active' => ['label' => 'Enabled', 'badges' => [1 => ['success', 'On'], 0 => ['danger', 'Off']]],
            'department.name' => ['label' => 'Department', 'empty' => 'Unassigned'],
            // A closure that badges only some rows: the rest stay plain text.
            'level' => ['badges' => fn (mixed $value): ?array => $value > 3 ? ['warning', 'High'] : null],
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('edit')->icon('<i class="far fa-edit"></i>')->route('users.edit'),
        ];
    }
}

<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Shwaeki\DynamicTable\Actions\BulkAction;
use Shwaeki\DynamicTable\DynamicTable;

/** Everything switched on, used by the feature and security tests. */
class FullUsersTable extends DynamicTable
{
    protected string $model = User::class;

    protected ?string $tableKey = 'full_users';

    protected array $features = [
        'views',
        'export',
        'import',
        'bulk-actions',
        'inline_edit',
        'column_picker',
        'column_reordering',
        'column_resizing',
        'column_search',
        'soft_deletes',
        'url_state',
    ];

    protected array $searchable = ['name', 'email', 'department.name'];

    protected array $hiddenColumns = ['level'];

    protected array $defaultSort = ['name' => 'asc'];

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'name',
            'email',
            'department.name' => 'Department',
            'role.name',
            'status',
            'is_active',
            'salary' => ['format' => 'currency:USD', 'align' => 'end'],
            'joined_at',
            'display_name' => ['label' => 'Display', 'visible' => false],
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    public function actions(): array
    {
        return [
            BulkAction::make('activate')
                ->label('Activate')
                ->handle(function ($query): int {
                    return $query->update(['is_active' => true]);
                }),
            BulkAction::delete(),
        ];
    }

    public function presets(): array
    {
        return [
            'active' => [
                'name' => 'Active users',
                'default' => false,
                'filters' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'is_active', 'operator' => 'equals', 'value' => true],
                    ],
                ],
            ],
        ];
    }
}

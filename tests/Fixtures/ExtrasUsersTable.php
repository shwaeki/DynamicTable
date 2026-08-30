<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Shwaeki\DynamicTable\Actions\ToolbarAction;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/** The later features — create, bulk edit, toolbar, detail, sticky, facets. */
class ExtrasUsersTable extends DynamicTable
{
    protected string $model = User::class;

    protected ?string $tableKey = 'extras_users';

    protected array $features = [
        Feature::CREATE,
        Feature::BULK_EDIT,
        Feature::TOOLBAR_ACTIONS,
        Feature::ROW_DETAIL,
        Feature::STICKY_COLUMNS,
        Feature::FACETS,
    ];

    protected array $stickyColumns = ['name'];

    protected bool $stickyActions = true;

    protected array $facets = ['status'];

    protected array $defaultSort = ['name' => 'asc'];

    protected function columns(): array
    {
        return [
            'name',
            'email',
            'status',
            'is_active',
            'salary' => ['align' => 'end'],
            'department.name' => 'Department',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'salary' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function newRecordDefaults(): array
    {
        return ['level' => 3, 'is_active' => true];
    }

    public function toolbar(): array
    {
        return [
            ToolbarAction::make('ping')
                ->label('Ping')
                ->handle(fn (): string => 'pong'),

            ToolbarAction::make('rename-everyone')
                ->label('Rename')
                ->fields(['suffix' => ['label' => 'Suffix', 'rules' => 'required|string|max:5']])
                ->handle(fn (DynamicTable $table, array $input): string => 'suffix:'.$input['suffix']),

            ToolbarAction::link('handbook', 'https://example.com/handbook'),

            ToolbarAction::make('forbidden')
                ->label('Forbidden')
                ->visible(fn (): bool => false)
                ->handle(fn (): string => 'should never run'),
        ];
    }

    public function rowDetail(Model $record): mixed
    {
        // Markup has to say so: a plain string is treated as text and escaped.
        return new HtmlString('<p data-detail>'.e($record->email).'</p>');
    }
}

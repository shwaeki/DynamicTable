<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Illuminate\Support\HtmlString;
use Shwaeki\DynamicTable\Actions\RowAction;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/** Icon-font row actions and a column the model has no attribute for. */
class MarkupUsersTable extends DynamicTable
{
    protected string $model = User::class;

    protected ?string $tableKey = 'markup_users';

    protected array $features = [Feature::ROW_ACTIONS];

    protected array $defaultSort = ['name' => 'asc'];

    protected function columns(): array
    {
        return [
            'avatar' => [
                'label' => 'Avatar',
                'sortable' => false,
                'searchable' => false,
                'render' => fn ($value, User $user): HtmlString => new HtmlString(
                    '<img src="'.e('/avatars/'.$user->getKey().'.png').'" alt="avatar">'
                ),
            ],
            'name',
            // Untyped on purpose: the returned Htmlable still has to survive.
            'email' => ['render' => fn ($value) => new HtmlString('<b>'.e((string) $value).'</b>')],
            'status' => ['render' => fn ($value): string => '<not markup>'],
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('edit')
                ->label('Edit')
                ->icon('<i class="far fa-edit"></i>')
                ->url(fn (User $user): string => '/users/'.$user->getKey().'/edit'),

            RowAction::make('archive')
                ->label('Archive')
                ->icon('🗄')
                ->destructive()
                ->handle(fn (User $user) => $user->getKey()),
        ];
    }
}

<?php

namespace App\DynamicTables;

use App\Models\Product;
use Shwaeki\DynamicTable\Actions\ToolbarAction;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Your own buttons in the table's toolbar.
 *
 * A toolbar action concerns the table rather than a row, so nothing is handed
 * to it except the input you ask for. A link goes straight where you point it;
 * a handler is posted back, authorised, run on the server, and the table is
 * repainted afterwards.
 */
class ToolbarActionsTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = [Feature::TOOLBAR_ACTIONS];

    protected function columns(): array
    {
        return [
            'name',
            'sku',
            'category.name' => 'Category',
            'status',
            'price' => ['format' => 'currency:USD', 'align' => 'end'],
            'stock' => ['align' => 'end'],
        ];
    }

    public function toolbar(): array
    {
        return [
            // Sits beside the search box rather than at the end.
            ToolbarAction::make('recount')
                ->label('Recount stock')
                ->icon('↻')
                ->alignStart()
                ->handle(fn (): string => 'Stock recounted from the warehouse feed.'),

            // Declared fields are collected in a dialog before the handler
            // runs, and validated on the server with ordinary Laravel rules.
            ToolbarAction::make('markdown')
                ->label('Apply markdown')
                ->icon('%')
                ->primary()
                ->fields([
                    'percent' => [
                        'label' => 'Percent off',
                        'type' => 'number',
                        'default' => 10,
                        'rules' => 'required|integer|min:1|max:90',
                    ],
                ])
                ->handle(fn (DynamicTable $table, array $input): string => "A {$input['percent']}% markdown would apply to this view."),

            ToolbarAction::link('catalogue', 'https://example.com/catalogue', '_blank')
                ->label('Public catalogue')
                ->icon('↗'),
        ];
    }
}

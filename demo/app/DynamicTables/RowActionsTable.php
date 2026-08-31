<?php

namespace App\DynamicTables;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\HtmlString;
use Shwaeki\DynamicTable\Actions\RowAction;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Buttons on every row, and small pieces of HTML inside cells.
 *
 * Row actions come in two kinds. A link goes wherever you point it. A handler
 * is posted back to Laravel, authorised against that specific record, run on
 * the server, and the row is repainted from the result — the button having been
 * rendered is never treated as permission.
 *
 * Visibility is per record too: "Publish" only appears on drafts, and
 * "Discontinue" disappears once a product already is.
 */
class RowActionsTable extends DynamicTable
{
    protected string $model = Product::class;

    protected array $features = ['row_actions'];

    protected function columns(): array
    {
        return [
            // A cell can hold markup. Returning an HtmlString says "this is
            // already safe HTML", so no separate raw flag is needed — but you
            // are then responsible for escaping what you interpolate.
            'image_url' => [
                'label' => '',
                'sortable' => false,
                'width' => 56,
                'render' => fn (?string $url, Product $product): HtmlString => new HtmlString(
                    $url === null
                        ? '<span class="dt-null">—</span>'
                        : '<img src="'.e($url).'" alt="'.e($product->name).'" class="demo-thumb">'
                ),
            ],
            'name',
            'sku',
            'price' => ['format' => 'currency:USD', 'align' => 'end'],
            'stock' => [
                'align' => 'end',
                'render' => fn (int $stock): HtmlString => new HtmlString(
                    '<span class="stock stock-'.($stock > 100 ? 'ok' : ($stock > 20 ? 'low' : 'critical')).'">'
                    .e((string) $stock).'</span>'
                ),
            ],
            'status',
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('view')
                ->label('Open')
                ->icon('↗')
                ->url(fn (Product $product): string => 'https://example.com/products/'.$product->sku, '_blank'),

            RowAction::make('feature')
                ->label('Toggle featured')
                ->icon('★')
                ->handle(fn (Product $product) => $product->update(['is_featured' => ! $product->is_featured])),

            // The loud version: its own classes, and the label drawn beside the
            // icon rather than left in the tooltip. An action that names classes
            // is not painted by the package at all, so it looks like the rest of
            // the application's buttons.
            RowAction::make('publish')
                ->label('Publish')
                ->icon('✔')
                ->class('demo-btn demo-btn-primary')
                ->withLabel()
                ->visible(fn (Product $product): bool => $product->status === ProductStatus::Draft)
                ->handle(fn (Product $product) => $product->update(['status' => ProductStatus::Active])),

            RowAction::make('discontinue')
                ->label('Discontinue')
                ->icon('⏻')
                ->confirm('Discontinue this product?')
                ->visible(fn (Product $product): bool => $product->status !== ProductStatus::Discontinued)
                ->handle(fn (Product $product) => $product->update(['status' => ProductStatus::Discontinued])),

            RowAction::delete(),
        ];
    }
}

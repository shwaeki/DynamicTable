<?php

namespace App\DynamicTables;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\Actions\BulkAction;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Authorisation and scoping.
 *
 * query() is a hard boundary: it runs before anything the user can influence
 * and is re-applied for exports, bulk actions and inline edits. Only unpaid
 * invoices exist as far as this table is concerned, whatever id a crafted
 * request sends.
 *
 * authorize() returns true/false to decide, or null to fall through to the
 * model policy. Here, deleting is denied — the action is not rendered, and the
 * endpoint rejects it too.
 */
class AuthorizationTable extends DynamicTable
{
    protected string $model = Invoice::class;

    protected array $features = ['bulk-actions', 'inline_edit', 'export'];

    public function query(Builder $query): Builder
    {
        return $query->where('status', 'unpaid');
    }

    public function authorize(string $ability, ?Model $record = null): ?bool
    {
        return match ($ability) {
            'delete', 'bulk-delete' => false,
            'export' => true,
            default => null,
        };
    }

    protected function columns(): array
    {
        return ['number', 'order.reference' => 'Order', 'amount', 'due_on' => 'Due', 'status'];
    }

    public function actions(): array
    {
        return [
            BulkAction::make('mark_paid')
                ->label('Mark as paid')
                ->ability('update')
                ->handle(fn ($query) => $query->update(['status' => 'paid', 'paid_at' => now()])),

            // Rendered nowhere and rejected server-side, because authorize() denies it.
            BulkAction::delete(),
        ];
    }
}

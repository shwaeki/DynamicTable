<?php

namespace App\DynamicTables;

use App\Models\Event1m;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * 1,000,000 rows.
 *
 * Pagination is left on "auto": the package asks the database for its own row
 * estimate — which is free, unlike COUNT(*) — and past
 * config('dynamic-table.pagination.count_threshold') it stops counting and
 * shows previous/next instead. At this size that is the difference between a
 * page that renders immediately and one that waits on a full table scan.
 */
class Scale1mTable extends DynamicTable
{
    protected string $model = Event1m::class;

    protected ?string $tableKey = 'scale_1m';

    protected array $features = ['column_picker'];

    protected string $pagination = 'auto';

    protected array $searchable = ['reference'];

    protected array $defaultSort = ['occurred_at' => 'desc'];

    protected function columns(): array
    {
        return [
            'reference' => 'Reference',
            'category',
            'region',
            'status',
            'amount' => ['format' => 'currency:USD', 'align' => 'end'],
            'occurred_at' => 'Occurred',
        ];
    }
}

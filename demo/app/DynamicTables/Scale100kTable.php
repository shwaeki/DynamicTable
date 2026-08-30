<?php

namespace App\DynamicTables;

use App\Models\Event100k;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * 100,000 rows.
 *
 * Still comfortably countable, so pagination stays length-aware and the UI can
 * show a real total and numbered pages. Nothing here is special: it is an
 * ordinary table over a properly indexed dataset.
 */
class Scale100kTable extends DynamicTable
{
    protected string $model = Event100k::class;

    protected ?string $tableKey = 'scale_100k';

    protected array $features = ['export', 'column_picker'];

    /** Counting 100k rows costs almost nothing, so keep the total. */
    protected string $pagination = 'length_aware';

    /** Indexed, and a prefix search rather than a leading wildcard. */
    protected array $searchable = ['reference'];

    /** Indexed, so deep pages stay cheap. */
    protected array $defaultSort = ['occurred_at' => 'desc'];

    protected function columns(): array
    {
        return [
            'reference' => 'Reference',
            'category',
            'region',
            'status',
            'amount' => ['format' => 'currency:USD', 'align' => 'end'],
            'quantity' => ['align' => 'end'],
            'occurred_at' => 'Occurred',
        ];
    }
}

<?php

namespace App\DynamicTables;

use App\Models\Event10m;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * 10,000,000 rows.
 *
 * Counting is switched off explicitly rather than left to the estimate. At this
 * size a COUNT(*) over a filtered set costs far more than the page itself, and
 * "previous / next" is a more honest answer than a total the user waited five
 * seconds for.
 *
 * Everything else is unchanged — this is the same class you would write for
 * ten rows, with three lines acknowledging the scale.
 */
class Scale10mTable extends DynamicTable
{
    protected string $model = Event10m::class;

    protected ?string $tableKey = 'scale_10m';

    protected array $features = ['column_picker'];

    /** No COUNT(*), ever. */
    protected string $pagination = 'simple';

    /** One indexed column. Searching six columns here would be a table scan. */
    protected array $searchable = ['reference'];

    protected array $defaultSort = ['occurred_at' => 'desc'];

    protected ?int $perPage = 25;

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

<?php

namespace Shwaeki\DynamicTable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person a view has been shared with.
 *
 * Read access only: the owner keeps the right to rename, edit and delete. A
 * recipient who wants their own version saves a copy.
 *
 * @property int|string $view_id
 * @property int|string $user_id
 * @property int|string|null $shared_by
 */
class DynamicTableViewShare extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return DynamicTableView::sharesTable();
    }

    /** @return BelongsTo<DynamicTableView, $this> */
    public function view(): BelongsTo
    {
        return $this->belongsTo(DynamicTableView::class, 'view_id');
    }
}

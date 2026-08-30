<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The shared shape of the large-dataset tables. Each scale gets its own
 * subclass pointing at its own physical table, so the row counts are real
 * rather than a filter over one shared table.
 */
abstract class ScaleEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'is_flagged' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected $appends = ['margin'];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'price' => 'decimal:2',
            'is_featured' => 'boolean',
            'attributes' => 'array',
            'released_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * A computed attribute. DynamicTable will show and export it, but marks it
     * as computed so it is never sorted, searched or filtered — it does not
     * exist at the SQL level.
     */
    public function getMarginAttribute(): string
    {
        return number_format(((float) $this->price) * 0.35, 2);
    }
}

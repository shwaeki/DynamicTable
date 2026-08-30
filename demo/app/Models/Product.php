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

    protected $appends = ['margin', 'rating', 'trend', 'tags', 'build_seconds'];

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

    /*
     * Everything below is derived from the id rather than stored, so the
     * renderers example has something to draw without another migration. They
     * are computed attributes: DynamicTable shows and exports them, but never
     * sorts, searches or filters by them, because they do not exist in SQL.
     */

    /** Out of five, for the rating renderer. */
    public function getRatingAttribute(): float
    {
        return round(2.5 + (($this->id * 7) % 26) / 10, 1);
    }

    /** Seven daily figures, for the sparkline. */
    public function getTrendAttribute(): array
    {
        return array_map(
            fn (int $day): int => 40 + (($this->id * ($day + 3)) % 60),
            range(0, 6),
        );
    }

    /** A handful of labels, for the chips renderer. */
    public function getTagsAttribute(): array
    {
        $pool = ['new', 'sale', 'bestseller', 'refurbished', 'limited', 'clearance'];

        return array_values(array_filter(
            $pool,
            fn (string $tag, int $index): bool => ($this->id + $index) % 3 === 0,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** Seconds, for the duration renderer. */
    public function getBuildSecondsAttribute(): int
    {
        return 45 + ($this->id * 137) % 7200;
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

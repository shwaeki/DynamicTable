<?php

namespace Shwaeki\DynamicTable\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One saved table state. Declarative configuration only — never generated SQL —
 * so a view survives schema changes and can be migrated forward.
 *
 * @property int|string $id
 * @property string $table_key
 * @property int|string|null $user_id
 * @property string $name
 * @property string|null $icon
 * @property array<string, mixed> $configuration
 * @property int $version
 * @property bool $is_system
 * @property bool $is_default
 * @property int $position
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class DynamicTableView extends Model
{
    protected $guarded = [];

    protected $casts = [
        'configuration' => 'array',
        'is_system' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function getTable(): string
    {
        return (string) config('dynamic-table.views.table', 'dynamic_table_views');
    }

    /** @param Builder<self> $query */
    public function scopeForTable(Builder $query, string $tableKey): Builder
    {
        return $query->where('table_key', $tableKey);
    }

    /**
     * Views this user may see: their own, every system view, and any view
     * someone has shared with them.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, int|string|null $userId): Builder
    {
        return $query->where(function (Builder $nested) use ($userId): void {
            $nested->where('is_system', true);

            if ($userId === null) {
                return;
            }

            $nested->orWhere('user_id', $userId)
                ->orWhereExists(function ($shares) use ($userId): void {
                    $shares->from(self::sharesTable())
                        ->whereColumn('view_id', $this->getTable().'.id')
                        ->where('user_id', $userId);
                });
        });
    }

    public static function sharesTable(): string
    {
        return (string) config('dynamic-table.views.shares_table', 'dynamic_table_view_shares');
    }

    /** @return HasMany<DynamicTableViewShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(DynamicTableViewShare::class, 'view_id');
    }

    public function isOwnedBy(int|string|null $userId): bool
    {
        return $userId !== null && (string) $this->user_id === (string) $userId;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'system' => (bool) $this->is_system,
            'default' => (bool) $this->is_default,
            'icon' => $this->icon,
            'configuration' => $this->configuration ?? [],
        ];
    }
}

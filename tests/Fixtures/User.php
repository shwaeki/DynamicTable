<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $appends = ['display_name'];

    protected $casts = [
        'status' => Status::class,
        'is_active' => 'boolean',
        'salary' => 'decimal:2',
        'settings' => 'array',
        'joined_at' => 'datetime',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(($this->name ?? '').' <'.($this->email ?? '').'>');
    }
}

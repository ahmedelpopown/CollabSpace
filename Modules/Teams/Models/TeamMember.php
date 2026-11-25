<?php

namespace Modules\Teams\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Identity\Models\User;

class TeamMember extends Pivot
{
    public const STATUS_ACTIVE = 'active';

    protected $table = 'team_members';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'user_id',
        'team_role',
        'membership_status',
        'added_by_user_id',
        'joined_at',
        'left_at',
    ];

    /** Scope membership queries to currently active memberships. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('membership_status', self::STATUS_ACTIVE);
    }

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}

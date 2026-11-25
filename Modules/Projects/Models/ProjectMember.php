<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Identity\Models\User;

class ProjectMember extends Pivot
{
    public const STATUS_ACTIVE = 'active';

    protected $table = 'project_members';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'project_id', 'user_id', 'membership_status', 'added_by_user_id',
        'invited_at', 'joined_at', 'removed_at',
    ];

    /** Scope membership queries to currently active memberships. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('membership_status', self::STATUS_ACTIVE);
    }

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'joined_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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

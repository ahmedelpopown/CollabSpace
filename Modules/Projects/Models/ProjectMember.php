<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Identity\Models\User;

class ProjectMember extends Pivot
{
    protected $table = 'project_members';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'project_id', 'user_id', 'membership_status', 'added_by_user_id',
        'invited_at', 'joined_at', 'removed_at',
    ];

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

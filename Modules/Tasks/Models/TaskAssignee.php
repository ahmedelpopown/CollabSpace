<?php

namespace Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Identity\Models\User;

class TaskAssignee extends Pivot
{
    protected $table = 'task_assignees';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['task_id', 'user_id', 'assigned_by_user_id', 'assigned_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

}

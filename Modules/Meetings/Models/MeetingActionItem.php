<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Identity\Models\User;
use Modules\Tasks\Models\Task;

class MeetingActionItem extends Model
{
    protected $fillable = [
        'summary_id', 'assigned_to_user_id', 'linked_task_id',
        'title', 'description', 'due_at', 'status',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(MeetingSummary::class, 'summary_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function linkedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'linked_task_id');
    }
}

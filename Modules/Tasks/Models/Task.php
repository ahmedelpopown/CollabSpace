<?php

namespace Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Meetings\Models\MeetingActionItem;
use Modules\Projects\Models\Project;
use Modules\Teams\Models\Team;

/** @property int $project_id */
class Task extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUSES = ['pending', 'in_progress', 'in_review', 'completed'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'progress_percentage',
        'due_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'string',
            'progress_percentage' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Tasks\Factories\TaskFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assigneeRecords(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees', 'task_id', 'user_id')
            ->using(TaskAssignee::class)
            ->withPivot(['assigned_by_user_id', 'assigned_at']);
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'task_files', 'task_id', 'file_id')
            ->using(\Modules\Files\Models\TaskFile::class)
            ->withPivot(['attached_by_user_id', 'attached_at']);
    }

    public function meetingActionItems(): HasMany
    {
        return $this->hasMany(MeetingActionItem::class, 'linked_task_id');
    }
}

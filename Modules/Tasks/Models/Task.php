<?php

namespace Modules\Tasks\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Models\AuditLog;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Meetings\Models\MeetingActionItem;
use Modules\Projects\Models\Project;
use Modules\Teams\Models\Team;

/**
 * @property int $project_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property Project $project
 */
class Task extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'assigned_team_id', 'created_by_user_id', 'title',
        'description', 'status', 'due_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
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

    public function assignAssignee(User $user, User $assignedBy): void
    {
        $project = $this->project;

        if (! $this->exists || $this->trashed()
            || ! $project
            || ! $project->canBeManagedBy($assignedBy)
            || ! $assignedBy->can('tasks.assign')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The user cannot assign tasks in this project.');
        }

        if (! $user->hasActiveProjectMembership((int) $this->project_id)) {
            throw new \DomainException('Only active project members can be assigned a task.');
        }

        DB::transaction(function () use ($user, $assignedBy): void {
            DB::table('task_assignees')->updateOrInsert(
                ['task_id' => $this->getKey(), 'user_id' => $user->getKey()],
                ['assigned_by_user_id' => $assignedBy->getKey(), 'assigned_at' => now()],
            );

            AuditLog::create([
                'actor_user_id' => $assignedBy->getKey(),
                'action' => 'task.assignee.assign',
                'subject_type' => static::class,
                'subject_id' => $this->getKey(),
                'new_values' => ['user_id' => $user->getKey()],
            ]);
        });
    }

    public function canBeAccessedBy(User $user): bool
    {
        $project = $this->project;

        if (! $this->exists || $this->trashed()
            || ! $project
            || ! $user->can('tasks.view')
            || ! $user->canAccessProject($project)) {
            return false;
        }

        if ($user->hasRole('Administrator') || $user->hasRole('Project Manager')) {
            return true;
        }

        return $this->assignees()->whereKey($user->getKey())->exists();
    }

    public function updateStatusByAssignee(User $user, string $status): bool
    {
        if (! \in_array($status, ['pending', 'in_progress', 'in_review', 'completed'], true)) {
            throw new \InvalidArgumentException('Unsupported task status.');
        }

        $project = $this->project;

        if (! $this->exists || $this->trashed()
            || ! $project
            || ! $user->can('tasks.status.update.assigned')
            || ! $user->canAccessProject($project)
            || ! $this->assignees()->whereKey($user->getKey())->exists()) {
            return false;
        }

        $previousStatus = $this->status;

        return DB::transaction(function () use ($user, $status, $previousStatus): bool {
            $this->status = $status;
            $this->completed_at = $status === 'completed' ? now() : null;

            if (! $this->save()) {
                return false;
            }

            if ($previousStatus !== $status) {
                AuditLog::create([
                    'actor_user_id' => $user->getKey(),
                    'action' => 'task.status.update.assigned',
                    'subject_type' => static::class,
                    'subject_id' => $this->getKey(),
                    'old_values' => ['status' => $previousStatus],
                    'new_values' => ['status' => $status],
                ]);
            }

            return true;
        });
    }

    public function updateStatusBy(User $user, string $status): bool
    {
        if ($user->hasRole('Team Member')) {
            return $this->updateStatusByAssignee($user, $status);
        }

        if (! \in_array($status, ['pending', 'in_progress', 'in_review', 'completed'], true)) {
            throw new \InvalidArgumentException('Unsupported task status.');
        }

        $project = $this->project;

        if (! $this->exists || $this->trashed() || ! $project
            || ! $project->canBeManagedBy($user) || ! $user->can('tasks.update')) {
            return false;
        }

        $previousStatus = $this->status;

        return DB::transaction(function () use ($user, $status, $previousStatus): bool {
            $this->status = $status;
            $this->completed_at = $status === 'completed' ? now() : null;

            if (! $this->save()) {
                return false;
            }

            if ($previousStatus !== $status) {
                AuditLog::create([
                    'actor_user_id' => $user->getKey(),
                    'action' => 'task.status.update',
                    'subject_type' => static::class,
                    'subject_id' => $this->getKey(),
                    'old_values' => ['status' => $previousStatus],
                    'new_values' => ['status' => $status],
                ]);
            }

            return true;
        });
    }
}

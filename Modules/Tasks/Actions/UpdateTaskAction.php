<?php

namespace Modules\Tasks\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Services\TaskAssigneeService;

class UpdateTaskAction
{
    public function __construct(
        private readonly TaskAssigneeService $assignees,
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    public function execute(Task $task, User $updater, array $data): Task
    {
        return DB::transaction(function () use ($task, $updater, $data): Task {
            $lockedTask = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($updater)->authorize('update', $lockedTask);

            $teamId = array_key_exists('assigned_team_id', $data)
                ? ($data['assigned_team_id'] !== null ? (int) $data['assigned_team_id'] : null)
                : ($lockedTask->assigned_team_id !== null ? (int) $lockedTask->assigned_team_id : null);

            $assigneeIds = $data['assignee_ids'] ?? $lockedTask->assignees()->pluck('users.id')->all();
            $needsAssigneeSync = array_key_exists('assignee_ids', $data) || array_key_exists('assigned_team_id', $data);
            if ($needsAssigneeSync) {
                Gate::forUser($updater)->authorize('assignAssignees', $lockedTask);
            }
            $eligibleAssignees = $needsAssigneeSync
                ? $this->assignees->resolveEligibleUsers($lockedTask->project, $teamId, $assigneeIds)
                : collect();

            $attributes = Arr::only($data, [
                'title', 'description', 'priority', 'progress_percentage', 'due_at', 'status',
            ]);

            $hasStatus = array_key_exists('status', $attributes);
            $hasProgress = array_key_exists('progress_percentage', $attributes);
            $requestedStatus = $attributes['status'] ?? $lockedTask->status;
            $requestedProgress = (int) ($attributes['progress_percentage'] ?? $lockedTask->progress_percentage);

            if (($hasStatus && $requestedStatus === 'completed') || ($hasProgress && $requestedProgress === 100)) {
                $attributes['status'] = 'completed';
                $attributes['progress_percentage'] = 100;
            } elseif ($lockedTask->status === 'completed' && (($hasStatus && $requestedStatus !== 'completed') || ($hasProgress && $requestedProgress < 100))) {
                if (! $hasStatus) {
                    $attributes['status'] = 'in_progress';
                }

                if (! $hasProgress) {
                    $attributes['progress_percentage'] = 0;
                }
            }

            $oldTeamId = $lockedTask->assigned_team_id;
            $oldValues = Arr::only($lockedTask->getAttributes(), array_keys($attributes));
            $lockedTask->fill($attributes);
            if ($lockedTask->status === 'completed') {
                $lockedTask->completed_at ??= now();
            } else {
                $lockedTask->completed_at = null;
            }
            $lockedTask->assigned_team_id = $teamId;
            $lockedTask->save();

            if ($needsAssigneeSync) {
                $this->assignees->sync($lockedTask, $eligibleAssignees, $updater);
            }

            $newValues = Arr::only($lockedTask->getAttributes(), array_keys($attributes));
            if ($oldValues !== $newValues || (int) ($oldTeamId ?? 0) !== (int) ($teamId ?? 0)) {
                $this->auditLog->execute(
                    $updater,
                    'task.update',
                    $lockedTask,
                    oldValues: $oldValues + ['assigned_team_id' => $oldTeamId],
                    newValues: $newValues + ['assigned_team_id' => $teamId],
                );
            }

            return $lockedTask;
        });
    }
}

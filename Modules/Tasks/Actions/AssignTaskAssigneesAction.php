<?php

namespace Modules\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Identity\Models\User;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Services\TaskAssigneeService;

class AssignTaskAssigneesAction
{
    public function __construct(private readonly TaskAssigneeService $assignees) {}

    public function execute(Task $task, array $userIds, User $assignedBy): Task
    {
        return DB::transaction(function () use ($task, $userIds, $assignedBy): Task {
            $lockedTask = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($assignedBy)->authorize('assignAssignees', $lockedTask);

            $users = $this->assignees->resolveEligibleUsers(
                $lockedTask->project,
                $lockedTask->assigned_team_id !== null ? (int) $lockedTask->assigned_team_id : null,
                $userIds,
            );
            $this->assignees->sync($lockedTask, $users, $assignedBy);

            return $lockedTask;
        });
    }
}

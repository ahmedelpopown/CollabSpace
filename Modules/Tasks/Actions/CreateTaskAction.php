<?php

namespace Modules\Tasks\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Services\TaskAssigneeService;

class CreateTaskAction
{
    public function __construct(
        private readonly TaskAssigneeService $assignees,
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    public function execute(Project $project, User $creator, array $data): Task
    {
        return DB::transaction(function () use ($project, $creator, $data): Task {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($creator)->authorize('create', [Task::class, $lockedProject]);

            $teamId = isset($data['assigned_team_id']) ? (int) $data['assigned_team_id'] : null;
            $assigneeIds = $data['assignee_ids'] ?? [];
            if ($teamId !== null || $assigneeIds !== []) {
                Gate::forUser($creator)->authorize('assignToProject', [Task::class, $lockedProject]);
            }
            $eligibleAssignees = $this->assignees->resolveEligibleUsers($lockedProject, $teamId, $assigneeIds);

            $task = new Task(Arr::only($data, [
                'title', 'description', 'priority', 'progress_percentage', 'due_at', 'status',
            ]));
            $task->project_id = $lockedProject->getKey();
            $task->assigned_team_id = $teamId;
            $task->created_by_user_id = $creator->getKey();
            $task->status = $data['status'] ?? 'pending';
            $task->progress_percentage = (int) ($data['progress_percentage'] ?? ($task->status === 'completed' ? 100 : 0));

            if ($task->status === 'completed') {
                $task->progress_percentage = 100;
                $task->completed_at = now();
            }
            $task->save();

            if ($eligibleAssignees->isNotEmpty()) {
                $this->assignees->sync($task, $eligibleAssignees, $creator);
            }

            $this->auditLog->execute(
                $creator,
                'task.create',
                $task,
                newValues: [
                    'project_id' => $lockedProject->getKey(),
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'progress_percentage' => $task->progress_percentage,
                    'due_at' => $task->due_at?->toISOString(),
                    'assigned_team_id' => $teamId,
                ],
            );

            return $task;
        });
    }
}

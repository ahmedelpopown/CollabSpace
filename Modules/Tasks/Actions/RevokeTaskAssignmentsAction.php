<?php

namespace Modules\Tasks\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;
use Modules\Teams\Models\Team;

/**
 * Removes task assignments when a user's project or team membership ends.
 * This action is intended to be called by the membership-removal workflows.
 */
class RevokeTaskAssignmentsAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLog) {}

    public function forProject(Project $project, User $member, User $actor): int
    {
        return $this->revoke(
            Task::withTrashed()->where('project_id', $project->getKey()),
            $member,
            $actor,
            'task.assignee.revoke.project',
        );
    }

    public function forTeam(Team $team, User $member, User $actor): int
    {
        return $this->revoke(
            Task::withTrashed()->where('assigned_team_id', $team->getKey()),
            $member,
            $actor,
            'task.assignee.revoke.team',
        );
    }

    private function revoke(Builder $tasksQuery, User $member, User $actor, string $auditAction): int
    {
        return DB::transaction(function () use ($tasksQuery, $member, $actor, $auditAction): int {
            $assignedTaskIds = DB::table('task_assignees')
                ->select('task_id')
                ->where('user_id', $member->getKey());

            $tasks = $tasksQuery
                ->whereIn('id', $assignedTaskIds)
                ->lockForUpdate()
                ->get();

            foreach ($tasks as $task) {
                $task->assignees()->detach($member->getKey());

                $this->auditLog->execute(
                    $actor,
                    $auditAction,
                    $task,
                    oldValues: ['assignee_user_id' => $member->getKey()],
                    newValues: ['assignee_removed' => true],
                );
            }

            return $tasks->count();
        });
    }
}

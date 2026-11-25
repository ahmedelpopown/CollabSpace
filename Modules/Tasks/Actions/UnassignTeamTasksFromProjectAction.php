<?php

namespace Modules\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;
use Modules\Teams\Models\Team;

/**
 * Clears the team foreign key from project tasks before a project-team pivot is detached.
 * Individual task assignees remain assigned because project membership is still independent.
 */
class UnassignTeamTasksFromProjectAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLog) {}

    public function execute(Project $project, Team $team, User $actor): int
    {
        return DB::transaction(function () use ($project, $team, $actor): int {
            $tasks = Task::withTrashed()
                ->where('project_id', $project->getKey())
                ->where('assigned_team_id', $team->getKey())
                ->lockForUpdate()
                ->get();

            foreach ($tasks as $task) {
                $task->forceFill(['assigned_team_id' => null])->save();

                $this->auditLog->execute(
                    $actor,
                    'task.team.unassign',
                    $task,
                    oldValues: ['assigned_team_id' => $team->getKey()],
                    newValues: ['assigned_team_id' => null],
                );
            }

            return $tasks->count();
        });
    }
}

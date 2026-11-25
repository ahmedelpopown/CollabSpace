<?php

namespace Modules\Projects\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Meetings\Actions\RevokeUpcomingParticipantsForProjectTeamAction;
use Modules\Projects\Models\Project;
use Modules\Tasks\Actions\UnassignTeamTasksFromProjectAction;
use Modules\Teams\Models\Team;

class DetachProjectTeamAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
        private readonly RevokeUpcomingParticipantsForProjectTeamAction $revokeUpcomingParticipants,
        private readonly UnassignTeamTasksFromProjectAction $unassignTeamTasks,
    ) {}

    public function execute(Project $project, Team $team, User $detachedBy): void
    {
        DB::transaction(function () use ($project, $team, $detachedBy): void {
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($detachedBy)->authorize('assignTeams', $lockedProject);

            $assignment = $lockedProject->teamAssignments()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                throw new \DomainException('The team is not assigned to this project.');
            }

            $this->unassignTeamTasks->execute($lockedProject, $team, $detachedBy);

            $this->revokeUpcomingParticipants->execute(
                (int) $lockedProject->getKey(),
                (int) $team->getKey(),
            );

            $lockedProject->teams()->detach($team->getKey());

            $this->auditLog->execute(
                $detachedBy,
                'project.team.detach',
                $lockedProject,
                oldValues: ['team_id' => $team->getKey()],
            );
        });
    }
}

<?php

namespace Modules\Projects\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\DTOs\ProjectTeamAssignmentResult;
use Modules\Projects\Models\Project;
use Modules\Teams\Models\Team;

class AssignProjectTeamAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    /**
     * Backward-compatible entry point for callers that already have a Team model.
     */
    public function execute(Project $project, Team $team, User $assignedBy): bool
    {
        return $this->executeById($project, (int) $team->getKey(), $assignedBy)->assigned;
    }

    /** Resolve and lock the Team once, avoiding a controller lookup followed by a second locked lookup. */
    public function executeById(Project $project, int $teamId, User $assignedBy): ProjectTeamAssignmentResult
    {
        return DB::transaction(function () use ($project, $teamId, $assignedBy): ProjectTeamAssignmentResult {
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($assignedBy)->authorize('assignTeams', $lockedProject);

            $lockedTeam = Team::query()
                ->whereKey($teamId)
                ->lockForUpdate()
                ->first();

            if (! $lockedTeam || ! $lockedTeam->is_active || $lockedTeam->trashed()) {
                throw ValidationException::withMessages([
                    'team_id' => ['Only active teams may be assigned to a project.'],
                ]);
            }

            $alreadyAssigned = $lockedProject->teamAssignments()
                ->where('team_id', $lockedTeam->getKey())
                ->exists();

            if ($alreadyAssigned) {
                return new ProjectTeamAssignmentResult($lockedTeam, false);
            }

            $lockedProject->teams()->attach($lockedTeam->getKey(), [
                'assigned_by_user_id' => $assignedBy->getKey(),
                'assigned_at' => now(),
            ]);

            $this->auditLog->execute(
                $assignedBy,
                'project.team.assign',
                $lockedProject,
                newValues: ['team_id' => $lockedTeam->getKey()],
            );

            return new ProjectTeamAssignmentResult($lockedTeam, true);
        });
    }
}

<?php

namespace Modules\Projects\Queries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

class EligibleProjectTeamMembersQuery
{
    public function forProject(Project $project, int $teamId): Builder
    {
        $query = User::query();

        if (! $project->exists || $project->trashed()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('is_active', true)
            ->whereHas('teamMemberships', function (Builder $membershipQuery) use ($project, $teamId): void {
                $membershipQuery
                    ->where('team_id', $teamId)
                    ->active()
                    ->whereHas('team', function (Builder $teamQuery) use ($project): void {
                        $teamQuery
                            ->where('is_active', true)
                            ->whereHas('projectAssignments', fn (Builder $assignmentQuery) =>
                                $assignmentQuery->where('project_id', $project->getKey())
                            );
                    });
            })
            ->orderBy('full_name')
            ->orderBy('id');
    }
}

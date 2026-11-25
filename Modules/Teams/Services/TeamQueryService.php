<?php

namespace Modules\Teams\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Models\Project;
use Modules\Teams\Models\Team;

class TeamQueryService
{
    public function paginateForProject(Project $project, array $filters = []): LengthAwarePaginator
    {
        $query = $project->teams()
            ->where('teams.is_active', true)
            ->with([
                'activeMembers' => fn (BelongsToMany $members) => $members
                    ->whereHas('projectMemberships', fn (Builder $memberships) => $memberships
                        ->where('project_id', $project->getKey())
                        ->active())
                    ->select('users.id', 'users.full_name', 'users.job_title', 'users.avatar_path')
                    ->orderBy('users.full_name'),
            ])
            ->withCount([
                'tasks as project_tasks_count' => fn (Builder $tasks) => $tasks->where('project_id', $project->getKey()),
                'tasks as completed_project_tasks_count' => fn (Builder $tasks) => $tasks
                    ->where('project_id', $project->getKey())
                    ->where('status', 'completed'),
            ])
            ->withAvg([
                'tasks as project_progress_percentage' => fn (Builder $tasks) => $tasks
                    ->where('project_id', $project->getKey()),
            ], 'progress_percentage');

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->where('teams.name', 'like', '%'.$search.'%');
        }

        $paginator = $query
            ->orderBy('teams.name')
            ->orderBy('teams.id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 50));

        $this->attachMemberTaskStatistics($paginator->getCollection(), (int) $project->getKey());

        return $paginator;
    }

    private function attachMemberTaskStatistics($teams, int $projectId): void
    {
        $teamIds = $teams->modelKeys();

        if ($teamIds === []) {
            return;
        }

        $statistics = DB::table('task_assignees as assignments')
            ->join('tasks', 'tasks.id', '=', 'assignments.task_id')
            ->join('team_members', 'team_members.user_id', '=', 'assignments.user_id')
            ->join('project_members', 'project_members.user_id', '=', 'assignments.user_id')
            ->whereIn('team_members.team_id', $teamIds)
            ->whereColumn('team_members.team_id', 'tasks.assigned_team_id')
            ->where('tasks.project_id', $projectId)
            ->whereNull('tasks.deleted_at')
            ->where('team_members.membership_status', 'active')
            ->where('project_members.project_id', $projectId)
            ->where('project_members.membership_status', 'active')
            ->selectRaw('team_members.team_id, assignments.user_id, COUNT(*) as tasks_count, SUM(CASE WHEN tasks.status = ? THEN 1 ELSE 0 END) as completed_tasks_count, AVG(tasks.progress_percentage) as progress_percentage', ['completed'])
            ->groupBy('team_members.team_id', 'assignments.user_id')
            ->get()
            ->keyBy(fn ($row): string => $row->team_id.':'.$row->user_id);

        foreach ($teams as $team) {
            foreach ($team->activeMembers as $member) {
                $stat = $statistics->get($team->getKey().':'.$member->getKey());
                $member->setAttribute('assigned_tasks_count', (int) ($stat->tasks_count ?? 0));
                $member->setAttribute('completed_tasks_count', (int) ($stat->completed_tasks_count ?? 0));
                $member->setAttribute('assigned_tasks_progress_percentage', round((float) ($stat->progress_percentage ?? 0), 2));
            }
        }
    }
}

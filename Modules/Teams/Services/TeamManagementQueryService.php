<?php

namespace Modules\Teams\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Identity\Models\User;
use Modules\Teams\Models\Team;

class TeamManagementQueryService
{
    public function paginateForOrganization(User $viewer, array $filters = []): LengthAwarePaginator
    {
        $query = Team::query()->with('owner:id,full_name,job_title,avatar_path');
        $this->applyVisibilityAndCounts($query, $viewer);

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('teams.name', 'like', '%'.$search.'%')
                    ->orWhere('teams.description', 'like', '%'.$search.'%');
            });
        }

        if (($status = $filters['status'] ?? null) === 'active') {
            $query->where('teams.is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('teams.is_active', false);
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('teams.created_at')->orderBy('teams.id'),
            'name_asc' => $query->orderBy('teams.name')->orderBy('teams.id'),
            'name_desc' => $query->orderByDesc('teams.name')->orderByDesc('teams.id'),
            'members_desc' => $query->orderByDesc('members_count')->orderBy('teams.name'),
            'projects_desc' => $query->orderByDesc('projects_count')->orderBy('teams.name'),
            default => $query->orderByDesc('teams.created_at')->orderByDesc('teams.id'),
        };

        return $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 50));
    }

    public function loadDetails(Team $team, User $viewer): Team
    {
        $canSeeAllTeamMembers = $this->canSeeAllTeamMembers($team, $viewer);
        $visibleProjectIds = $this->visibleProjectIdsQuery($viewer);

        $team->load([
            'owner:id,full_name,job_title,avatar_path',
            'activeMembers' => function (BelongsToMany $members) use ($canSeeAllTeamMembers, $visibleProjectIds): void {
                if (! $canSeeAllTeamMembers) {
                    $members->whereHas('projectMemberships', fn (Builder $memberships) => $memberships
                        ->whereIn('project_id', $visibleProjectIds)
                        ->active());
                }

                $members->select('users.id', 'users.full_name', 'users.job_title', 'users.avatar_path')
                    ->orderBy('users.full_name');
            },
            'projects' => function (BelongsToMany $projects) use ($viewer): void {
                if (! $viewer->can('projects.view.all')) {
                    $projects->whereHas('memberships', fn (Builder $memberships) => $memberships
                        ->where('user_id', $viewer->getKey())
                        ->active());
                }

                $projects->select('projects.id', 'projects.name', 'projects.status');
            },
        ]);

        $countRelations = $viewer->can('teams.view.all')
            ? $this->unrestrictedCountRelations()
            : $this->restrictedCountRelations($viewer, $visibleProjectIds, $canSeeAllTeamMembers);

        $team->loadCount($countRelations);

        return $team;
    }

    private function applyVisibilityAndCounts(Builder $query, User $viewer): void
    {
        if ($viewer->can('teams.view.all')) {
            $query->withCount($this->unrestrictedCountRelations());

            return;
        }

        $visibleProjectIds = $this->visibleProjectIdsQuery($viewer);

        $query->withCount($this->restrictedCountRelations(
            $viewer,
            $visibleProjectIds,
            false,
        ));

        $this->restrictToVisibleTeams($query, $viewer);
        $query->where('teams.is_active', true);
    }

    private function unrestrictedCountRelations(): array
    {
        return [
            'activeMembers as members_count',
            'projects as projects_count',
            'tasks as tasks_count',
        ];
    }

    private function restrictedCountRelations(
        User $viewer,
        Builder $visibleProjectIds,
        bool $canSeeAllTeamMembers,
    ): array {
        return [
            'activeMembers as members_count' => function (Builder $members) use ($viewer, $visibleProjectIds, $canSeeAllTeamMembers): void {
                if (! $canSeeAllTeamMembers) {
                    $this->restrictMemberCountToTeamOrProjectAccess($members, $viewer, $visibleProjectIds);
                }
            },
            'projects as projects_count' => fn (Builder $projects) => $projects
                ->whereIn('projects.id', $visibleProjectIds),
            'tasks as tasks_count' => fn (Builder $tasks) => $tasks
                ->whereIn('project_id', $visibleProjectIds),
        ];
    }

    private function visibleProjectIdsQuery(User $viewer): Builder
    {
        return $viewer->projectMemberships()
            ->getQuery()
            ->active()
            ->select('project_id');
    }

    private function canSeeAllTeamMembers(Team $team, User $viewer): bool
    {
        return $viewer->can('teams.view.all')
            || (int) $team->owner_user_id === (int) $viewer->getKey()
            || $team->memberships()
                ->where('user_id', $viewer->getKey())
                ->active()
                ->exists();
    }

    private function restrictMemberCountToTeamOrProjectAccess(
        Builder $members,
        User $viewer,
        Builder $visibleProjectIds,
    ): void {
        $members->where(function (Builder $visible) use ($viewer, $visibleProjectIds): void {
            $visible->whereExists(function (QueryBuilder $membership) use ($viewer): void {
                $membership->selectRaw('1')
                    ->from('team_members as viewer_team_membership')
                    ->whereColumn('viewer_team_membership.team_id', 'teams.id')
                    ->where('viewer_team_membership.user_id', $viewer->getKey())
                    ->where('viewer_team_membership.membership_status', 'active');
            })->orWhereHas('projectMemberships', fn (Builder $memberships) => $memberships
                ->whereIn('project_id', $visibleProjectIds)
                ->active());
        });
    }

    private function restrictToVisibleTeams(Builder $query, User $viewer): void
    {
        $query->where(function (Builder $visible) use ($viewer): void {
            $visible->where('teams.owner_user_id', $viewer->getKey())
                ->orWhereHas('memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $viewer->getKey())
                    ->active())
                ->orWhereHas('projects.memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $viewer->getKey())
                    ->active());
        });
    }
}

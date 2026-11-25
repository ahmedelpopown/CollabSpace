<?php

namespace Modules\Projects\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

class ProjectQueryService
{
    public function paginateVisibleTo(User $viewer, array $filters = []): LengthAwarePaginator
    {
        $query = $this->visibleProjectsQuery($viewer)
            ->with([
                'creator:id,full_name,avatar_path',
                'teams:id,name',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        $this->applySorting($query, (string) ($filters['sort'] ?? 'newest'));

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 50);

        return $this->withSummaryCounts($query)
            ->paginate($perPage)
            ->appends(array_filter([
                'search' => $filters['search'] ?? null,
                'status' => $filters['status'] ?? null,
                'priority' => $filters['priority'] ?? null,
                'sort' => $filters['sort'] ?? null,
                'per_page' => $perPage,
            ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    public function loadDetails(Project $project): Project
    {
        return $this->withSummaryCounts(Project::query()->whereKey($project->getKey()))
            ->with([
                'creator:id,full_name,avatar_path',
                'teams:id,name',
                'activeMembers:id,full_name,avatar_path',
            ])
            ->firstOrFail();
    }

    private function visibleProjectsQuery(User $viewer): Builder
    {
        $query = Project::query();

        if (! $viewer->can('projects.view.all')) {
            $query->whereHas('memberships', function (Builder $membershipQuery) use ($viewer): void {
                $membershipQuery
                    ->where('user_id', $viewer->getKey())
                    ->active();
            });
        }

        return $query;
    }

    private function withSummaryCounts(Builder $query): Builder
    {
        return $query->withCount([
            'tasks',
            'tasks as completed_tasks_count' => fn (Builder $tasks): Builder => $tasks->where('status', 'completed'),
            'tasks as in_progress_tasks_count' => fn (Builder $tasks): Builder => $tasks->where('status', 'in_progress'),
            'tasks as in_review_tasks_count' => fn (Builder $tasks): Builder => $tasks->where('status', 'in_review'),
            'tasks as pending_tasks_count' => fn (Builder $tasks): Builder => $tasks->where('status', 'pending'),
            'files',
            'teams',
            'activeMembers',
        ]);
    }

    private function applySorting(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'deadline_soon' => $query
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline')
                ->orderByDesc('id'),
            'deadline_latest' => $query
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('deadline')
                ->orderByDesc('id'),
            'name_asc' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}

<?php

namespace Modules\Tasks\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Http\Resources\Api\V1\TaskResource;
use Modules\Tasks\Models\Task;

class TaskQueryService
{
    private const STATUS_LABELS = [
        'pending' => 'To-do',
        'in_progress' => 'In Progress',
        'in_review' => 'In Review',
        'completed' => 'Completed Tasks',
    ];

    public function board(Project $project, User $viewer, array $filters = []): array
    {
        $filteredQuery = $this->visibleTasksQuery($project, $viewer);
        $this->applyFilters($filteredQuery, $filters);

        // One grouped count query replaces the four COUNT queries previously
        // executed by paginate(), while each column keeps independent paging.
        $statusTotals = (clone $filteredQuery)
            ->reorder()
            ->select('status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // Resolve each column's visible IDs first, then eager-load the union in
        // one batch. This avoids repeating relation queries for every column.
        $perColumn = min(max((int) ($filters['per_column'] ?? 15), 1), 30);
        $columnPages = [];
        $allTaskIds = [];
        $summary = ['total' => 0];

        foreach (Task::STATUSES as $status) {
            $pageName = 'page_'.$status;
            $currentPage = LengthAwarePaginator::resolveCurrentPage($pageName);
            $total = (int) ($statusTotals[$status] ?? 0);

            $ids = (clone $filteredQuery)
                ->where('status', $status)
                ->reorder()
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at')
                ->orderByDesc('id')
                ->forPage($currentPage, $perColumn)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $columnPages[$status] = [
                'page_name' => $pageName,
                'current_page' => $currentPage,
                'total' => $total,
                'ids' => $ids,
            ];

            array_push($allTaskIds, ...$ids);
            $summary[$status] = $total;
            $summary['total'] += $total;
        }

        $tasksById = collect();

        if ($allTaskIds !== []) {
            $tasksById = $this->withTaskBoardData(
                (clone $filteredQuery)->whereKey($allTaskIds),
            )->get()->keyBy(fn (Task $task): int => (int) $task->getKey());
        }

        $columns = [];

        foreach (Task::STATUSES as $status) {
            $page = $columnPages[$status];
            $items = collect($page['ids'])
                ->map(fn (int $taskId) => $tasksById->get($taskId))
                ->filter()
                ->values();

            $paginator = new LengthAwarePaginator(
                $items,
                $page['total'],
                $perColumn,
                $page['current_page'],
                [
                    'path' => LengthAwarePaginator::resolveCurrentPath(),
                    'query' => request()->query(),
                    'pageName' => $page['page_name'],
                ],
            );

            $columns[] = [
                'status' => $status,
                'label' => self::STATUS_LABELS[$status],
                'tasks' => TaskResource::collection($paginator)->resolve(request()),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $page['total'],
                    'last_page' => $paginator->lastPage(),
                    'next_page_url' => $paginator->nextPageUrl(),
                    'prev_page_url' => $paginator->previousPageUrl(),
                ],
            ];
        }

        return [
            'project' => [
                'id' => $project->getKey(),
                'name' => $project->name,
            ],
            'columns' => $columns,
            'summary' => $summary,
        ];
    }

    public function loadDetails(Project $project, Task $task): Task
    {
        $query = $project->tasks()
            ->whereKey($task->getKey())
            ->getQuery();

        return $this->withTaskBoardData($query)->firstOrFail();
    }

    private function visibleTasksQuery(Project $project, User $viewer): Builder
    {
        $query = $project->tasks()->getQuery();

        if (! $viewer->can('tasks.view.all')) {
            $query->whereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($viewer->getKey()))
                ->where(function (Builder $teamScope) use ($viewer): void {
                    $teamScope->whereNull('assigned_team_id')
                        ->orWhereHas('assignedTeam', fn (Builder $team) => $team
                            ->where('is_active', true)
                            ->whereHas('memberships', fn (Builder $memberships) => $memberships
                                ->where('user_id', $viewer->getKey())
                                ->active()));
                });
        }

        return $query;
    }

    /** Apply the same relationship loading and aggregate projections to board and details queries. */
    private function withTaskBoardData(Builder $query): Builder
    {
        return $query
            ->with([
                'assignedTeam:id,name',
                'assignees' => fn (BelongsToMany $assignees) => $assignees
                    ->where('users.is_active', true)
                    ->select('users.id', 'users.full_name', 'users.job_title', 'users.avatar_path'),
                'creator:id,full_name,job_title,avatar_path',
            ])
            ->withCount([
                'assignees' => fn (Builder $assignees) => $assignees->where('users.is_active', true),
                'files',
            ]);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['assigned_team_id'])) {
            $query->where('assigned_team_id', $filters['assigned_team_id']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->whereHas('assignees', fn (Builder $assignees) => $assignees
                ->whereKey($filters['assignee_id'])
                ->where('users.is_active', true));
        }

        if (! empty($filters['due_from'])) {
            $query->whereDate('due_at', '>=', $filters['due_from']);
        }

        if (! empty($filters['due_to'])) {
            $query->whereDate('due_at', '<=', $filters['due_to']);
        }
    }
}

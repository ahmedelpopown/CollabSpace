<?php

namespace Modules\Tasks\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;
use Modules\Teams\Models\Team;

class TaskAssigneeService
{
    public function __construct(private readonly RecordAuditLogAction $auditLog) {}

    /** @return Collection<int, User> */
    public function resolveEligibleUsers(Project $project, ?int $teamId, array $userIds): Collection
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($teamId !== null) {
            $team = Team::query()
                ->whereKey($teamId)
                ->where('is_active', true)
                ->whereHas('projectAssignments', fn ($query) => $query->where('project_id', $project->getKey()))
                ->lockForUpdate()
                ->first();

            if (! $team) {
                throw ValidationException::withMessages([
                    'assigned_team_id' => ['The selected team is not actively assigned to this project.'],
                ]);
            }
        } else {
            $team = null;
        }

        if ($userIds === []) {
            return collect();
        }

        $users = User::query()
            ->whereKey($userIds)
            ->where('is_active', true)
            ->whereHas('projectMemberships', function ($query) use ($project): void {
                $query->where('project_id', $project->getKey())
                    ->active();
            })
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (User $user): int => (int) $user->getKey());

        if ($users->count() !== count($userIds)) {
            throw ValidationException::withMessages([
                'assignee_ids' => ['Every assignee must be an active member of this project.'],
            ]);
        }

        if ($team !== null) {
            $eligibleIds = $team->memberships()
                ->whereIn('user_id', $userIds)
                ->active()
                ->lockForUpdate()
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (count($eligibleIds) !== count($userIds)) {
                throw ValidationException::withMessages([
                    'assignee_ids' => ['When a team is assigned, every assignee must also be an active member of that team.'],
                ]);
            }
        }

        return $users;
    }

    public function sync(Task $task, Collection $assignees, User $assignedBy): void
    {
        // Read only pivot metadata; loading complete User models here is unnecessary.
        $currentAssignments = DB::table('task_assignees')
            ->where('task_id', $task->getKey())
            ->get(['user_id', 'assigned_by_user_id', 'assigned_at'])
            ->keyBy('user_id');

        $currentIds = $currentAssignments->keys()->map(fn ($id): int => (int) $id)->all();
        $newIds = $assignees->keys()->map(fn ($id): int => (int) $id)->all();

        $syncData = $assignees->mapWithKeys(function (User $user) use ($currentAssignments, $assignedBy): array {
            $current = $currentAssignments->get((int) $user->getKey());

            return [
                $user->getKey() => [
                    'assigned_by_user_id' => $current?->assigned_by_user_id ?? $assignedBy->getKey(),
                    'assigned_at' => $current?->assigned_at ?? now(),
                ],
            ];
        })->all();

        $task->assignees()->sync($syncData);

        $added = array_values(array_diff($newIds, $currentIds));
        $removed = array_values(array_diff($currentIds, $newIds));

        if ($added !== [] || $removed !== []) {
            $this->auditLog->execute(
                $assignedBy,
                'task.assignees.sync',
                $task,
                oldValues: ['assignee_ids' => $currentIds],
                newValues: ['assignee_ids' => $newIds, 'added_user_ids' => $added, 'removed_user_ids' => $removed],
            );
        }
    }
}

<?php

namespace Modules\Tasks\Policies;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

class TaskPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->isActive($user)
            && $user->can('tasks.view')
            && Gate::forUser($user)->allows('view', $project);
    }

    public function view(User $user, Task $task): bool
    {
        if (! $this->isActive($user)
            || ! $user->can('tasks.view')
            || ! $task->exists
            || $task->trashed()
            || ! $task->project
            || ! Gate::forUser($user)->allows('view', $task->project)) {
            return false;
        }

        if ($user->can('tasks.view.all')) {
            return true;
        }

        return $this->isAssignedAndEligibleForTeam($user, $task);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->isActive($user)
            && $user->can('tasks.create')
            && Gate::forUser($user)->allows('update', $project);
    }

    public function assignToProject(User $user, Project $project): bool
    {
        return $this->isActive($user)
            && $user->can('tasks.assign')
            && Gate::forUser($user)->allows('update', $project);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->isActive($user)
            && $user->can('tasks.update')
            && $task->exists
            && ! $task->trashed()
            && $task->project !== null
            && Gate::forUser($user)->allows('update', $task->project);
    }

    public function updateAssignedStatus(User $user, Task $task): bool
    {
        return $this->isActive($user)
            && $user->can('tasks.view')
            && $user->can('tasks.status.update.assigned')
            && $task->exists
            && ! $task->trashed()
            && $task->project !== null
            && Gate::forUser($user)->allows('view', $task->project)
            && $this->isAssignedAndEligibleForTeam($user, $task);
    }

    public function assignAssignees(User $user, Task $task): bool
    {
        return $this->update($user, $task) && $user->can('tasks.assign');
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task) && $user->can('tasks.delete');
    }


    private function isAssignedAndEligibleForTeam(User $user, Task $task): bool
    {
        if (! $task->assignees()->whereKey($user->getKey())->exists()) {
            return false;
        }

        if ($task->assigned_team_id === null) {
            return true;
        }

        return $task->assignedTeam()
            ->where('is_active', true)
            ->whereHas('memberships', fn (Builder $memberships) => $memberships
                ->where('user_id', $user->getKey())
                ->active())
            ->exists();
    }

    private function isActive(User $user): bool
    {
        return $user->exists && $user->is_active && ! $user->trashed();
    }
}

<?php

namespace Modules\Teams\Policies;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Teams\Models\Team;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActive($user)
            && ($user->can('teams.view.all') || $user->can('teams.view.assigned'));
    }

    public function viewAnyInProject(User $user, Project $project): bool
    {
        return $this->viewAny($user)
            && Gate::forUser($user)->allows('view', $project);
    }

    public function view(User $user, Team $team): bool
    {
        if (! $this->isActive($user) || ! $team->exists || $team->trashed()) {
            return false;
        }

        // Administrators may inspect inactive teams while managing the organization.
        if ($user->can('teams.view.all')) {
            return true;
        }

        if (! $team->is_active || ! $user->can('teams.view.assigned')) {
            return false;
        }

        if ((int) $team->owner_user_id === (int) $user->getKey()) {
            return true;
        }

        return $team->memberships()
            ->where('user_id', $user->getKey())
            ->active()
            ->exists()
            || $team->projects()
                ->whereHas('memberships', fn (Builder $memberships) => $memberships
                    ->where('user_id', $user->getKey())
                    ->active())
                ->exists();
    }

    public function create(User $user): bool
    {
        return $this->isActive($user) && $user->can('teams.create');
    }

    public function update(User $user, Team $team): bool
    {
        return $this->isActive($user)
            && $team->exists
            && ! $team->trashed()
            && $user->can('teams.update');
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->isActive($user)
            && $team->exists
            && ! $team->trashed()
            && $user->can('teams.delete');
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $this->isActive($user)
            && $team->exists
            && ! $team->trashed()
            && $team->is_active
            && $user->can('teams.members.assign');
    }

    private function isActive(User $user): bool
    {
        return $user->exists && $user->is_active && ! $user->trashed();
    }
}

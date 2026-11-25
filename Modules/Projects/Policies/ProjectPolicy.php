<?php

namespace Modules\Projects\Policies;

use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActive($user) && $user->can('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project);
    }

    public function create(User $user): bool
    {
        return $this->isActive($user) && $user->can('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project)
            && $user->can('projects.update');
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project)
            && $user->can('projects.delete');
    }

    public function assignMembers(User $user, Project $project): bool
    {
        return $this->update($user, $project)
            && $user->can('projects.members.assign');
    }

    public function assignTeams(User $user, Project $project): bool
    {
        return $this->update($user, $project)
            && $user->can('projects.teams.assign');
    }

    public function viewFiles(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project)
            && $user->can('files.view');
    }

    public function uploadFiles(User $user, Project $project): bool
    {
        return $this->viewFiles($user, $project)
            && $user->can('files.upload');
    }

    private function hasProjectAccess(User $user, Project $project): bool
    {
        if (! $this->isActive($user)
            || ! $project->exists
            || $project->trashed()
            || ! $user->can('projects.view')) {
            return false;
        }

        if ($user->can('projects.view.all')) {
            return true;
        }

        return $project->memberships()
            ->where('user_id', $user->getKey())
            ->active()
            ->exists();
    }

    private function isActive(User $user): bool
    {
        return $user->exists
            && $user->is_active
            && ! $user->trashed();
    }
}

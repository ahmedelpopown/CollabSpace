<?php

namespace Modules\Files\Policies;

use Illuminate\Support\Facades\Gate;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

class FilePolicy
{
    public function view(User $user, File $file): bool
    {
        if (! $this->isActive($user)
            || ! $user->can('files.view')
            || ! $file->exists
            || $file->trashed()) {
            return false;
        }

        if ($user->can('files.view.all')) {
            return true;
        }

        $activeMembership = fn ($memberships) => $memberships
            ->where('user_id', $user->getKey())
            ->active();

        return $file->projects()->whereHas('memberships', $activeMembership)->exists()
            || $file->tasks()->whereHas('project.memberships', $activeMembership)->exists()
            || $file->messageAttachments()
                ->whereHas('message.project.memberships', $activeMembership)
                ->exists();
    }

    public function uploadToProject(User $user, Project $project): bool
    {
        return $this->isActive($user)
            && $user->can('files.view')
            && $user->can('files.upload')
            && Gate::forUser($user)->allows('view', $project);
    }

    public function attachToChat(User $user, File $file, Project $project): bool
    {
        if (! $this->isActive($user)
            || ! $user->can('chat.send')
            || ! $user->can('files.upload')
            || ! $file->exists
            || $file->trashed()
            || ! Gate::forUser($user)->allows('view', $project)) {
            return false;
        }

        $isUnattachedUploadOwnedByUser = (int) $file->uploaded_by_user_id === (int) $user->getKey()
            && ! $file->projects()->exists()
            && ! $file->tasks()->exists()
            && ! $file->messageAttachments()->exists();

        return $isUnattachedUploadOwnedByUser || $this->view($user, $file);
    }

    private function isActive(User $user): bool
    {
        return $user->exists && $user->is_active && ! $user->trashed();
    }
}

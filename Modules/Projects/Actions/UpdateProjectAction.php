<?php

namespace Modules\Projects\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

class UpdateProjectAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    public function execute(Project $project, User $updater, array $data): Project
    {
        $attributes = Arr::only($data, [
            'name',
            'description',
            'starts_on',
            'deadline',
            'priority',
            'status',
        ]);

        return DB::transaction(function () use ($project, $updater, $attributes): Project {
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($updater)->authorize('update', $lockedProject);

            $lockedProject->fill($attributes);

            if (! $lockedProject->isDirty()) {
                return $lockedProject;
            }

            $dirtyAttributes = $lockedProject->getDirty();
            $oldValues = Arr::only($lockedProject->getOriginal(), array_keys($dirtyAttributes));

            $lockedProject->save();

            $this->auditLog->execute(
                $updater,
                'project.update',
                $lockedProject,
                oldValues: $oldValues,
                newValues: Arr::only($lockedProject->getAttributes(), array_keys($dirtyAttributes)),
            );

            return $lockedProject;
        });
    }
}

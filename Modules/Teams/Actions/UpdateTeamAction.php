<?php

namespace Modules\Teams\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Teams\Models\Team;

class UpdateTeamAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    public function execute(Team $team, User $updater, array $data): Team
    {
        $attributes = Arr::only($data, ['name', 'description']);

        return DB::transaction(function () use ($team, $updater, $attributes): Team {
            $lockedTeam = Team::query()
                ->whereKey($team->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($updater)->authorize('update', $lockedTeam);

            $lockedTeam->fill($attributes);

            if (! $lockedTeam->isDirty()) {
                return $lockedTeam;
            }

            $dirty = $lockedTeam->getDirty();
            $oldValues = Arr::only($lockedTeam->getOriginal(), array_keys($dirty));

            $lockedTeam->save();

            $this->auditLog->execute(
                $updater,
                'team.update',
                $lockedTeam,
                oldValues: $oldValues,
                newValues: Arr::only($lockedTeam->getAttributes(), array_keys($dirty)),
            );

            return $lockedTeam;
        });
    }
}

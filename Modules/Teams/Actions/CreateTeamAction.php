<?php

namespace Modules\Teams\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Teams\Models\Team;

class CreateTeamAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    public function execute(User $creator, array $data): Team
    {
        Gate::forUser($creator)->authorize('create', Team::class);

        return DB::transaction(function () use ($creator, $data): Team {
            $ownerId = (int) ($data['owner_user_id'] ?? $creator->getKey());

            $owner = User::query()
                ->whereKey($ownerId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $owner || $owner->trashed()) {
                throw ValidationException::withMessages([
                    'owner_user_id' => ['The selected team owner is unavailable or inactive.'],
                ]);
            }

            $team = new Team([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
            ]);
            $team->owner_user_id = $owner->getKey();
            $team->is_active = true;
            $team->save();

            // The selected owner is also the initial Team Lead.
            DB::table('team_members')->insert([
                'team_id' => $team->getKey(),
                'user_id' => $owner->getKey(),
                'team_role' => 'lead',
                'membership_status' => 'active',
                'added_by_user_id' => $creator->getKey(),
                'joined_at' => now(),
                'left_at' => null,
            ]);

            $this->auditLog->execute(
                $creator,
                'team.create',
                $team,
                newValues: [
                    'name' => $team->name,
                    'owner_user_id' => $owner->getKey(),
                    'initial_lead_user_id' => $owner->getKey(),
                ],
            );

            return $team;
        });
    }
}

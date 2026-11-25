<?php

namespace Modules\Teams\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Meetings\Actions\RevokeUpcomingParticipantsForTeamMemberAction;
use Modules\Tasks\Actions\RevokeTaskAssignmentsAction;
use Modules\Teams\Models\Team;

class RemoveTeamMemberAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
        private readonly RevokeUpcomingParticipantsForTeamMemberAction $revokeParticipants,
        private readonly RevokeTaskAssignmentsAction $revokeTaskAssignments,
    ) {}

    public function execute(Team $team, User $member, User $removedBy): void
    {
        DB::transaction(function () use ($team, $member, $removedBy): void {
            $lockedTeam = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($removedBy)->authorize('manageMembers', $lockedTeam);

            $membership = DB::table('team_members')
                ->where('team_id', $lockedTeam->getKey())
                ->where('user_id', $member->getKey())
                ->lockForUpdate()
                ->first();

            if (! $membership || $membership->membership_status !== 'active') {
                return;
            }

            if ((int) $lockedTeam->owner_user_id === (int) $member->getKey()) {
                throw ValidationException::withMessages([
                    'user_id' => ['Transfer team ownership before removing the current owner.'],
                ]);
            }

            if ($membership->team_role === 'lead') {
                $activeLeadCount = DB::table('team_members')
                    ->where('team_id', $lockedTeam->getKey())
                    ->where('membership_status', 'active')
                    ->where('team_role', 'lead')
                    ->count();

                if ($activeLeadCount <= 1) {
                    throw ValidationException::withMessages([
                        'user_id' => ['Assign another active team lead before removing the last lead.'],
                    ]);
                }
            }

            DB::table('team_members')
                ->where('team_id', $lockedTeam->getKey())
                ->where('user_id', $member->getKey())
                ->update(['membership_status' => 'inactive', 'left_at' => now()]);

            $this->revokeParticipants->execute((int) $lockedTeam->getKey(), (int) $member->getKey());
            $this->revokeTaskAssignments->forTeam($lockedTeam, $member, $removedBy);

            $this->auditLog->execute(
                $removedBy,
                'team.member.remove',
                $lockedTeam,
                oldValues: ['user_id' => $member->getKey(), 'team_role' => $membership->team_role],
                newValues: ['membership_status' => 'inactive'],
            );
        });
    }
}

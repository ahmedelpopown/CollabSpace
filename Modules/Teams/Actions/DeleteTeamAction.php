<?php

namespace Modules\Teams\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Meetings\Actions\RevokeUpcomingParticipantsForTeamAction;
use Modules\Teams\Models\Team;

class DeleteTeamAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
        private readonly RevokeUpcomingParticipantsForTeamAction $revokeUpcomingParticipants,
    ) {}

    public function execute(Team $team, User $deletedBy): void
    {
        DB::transaction(function () use ($team, $deletedBy): void {
            $lockedTeam = Team::query()
                ->whereKey($team->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($deletedBy)->authorize('delete', $lockedTeam);

            // A Team must be detached from every Project first. Detaching also
            // clears team-scoped Task assignments and revokes upcoming invitations.
            if ($lockedTeam->projectAssignments()->exists()) {
                throw ValidationException::withMessages([
                    'team' => ['Detach this team from all projects before deleting it.'],
                ]);
            }

            // Protect against inconsistent legacy data before soft-deleting the team.
            if (DB::table('tasks')->where('assigned_team_id', $lockedTeam->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'team' => ['Tasks are still assigned to this team. Remove the team assignment before deleting it.'],
                ]);
            }

            $wasActive = (bool) $lockedTeam->is_active;

            $activeMemberIds = DB::table('team_members')
                ->where('team_id', $lockedTeam->getKey())
                ->where('membership_status', 'active')
                ->lockForUpdate()
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            DB::table('team_members')
                ->where('team_id', $lockedTeam->getKey())
                ->where('membership_status', 'active')
                ->update([
                    'membership_status' => 'inactive',
                    'left_at' => now(),
                ]);

            $revokedInvitations = $this->revokeUpcomingParticipants->execute((int) $lockedTeam->getKey());

            $lockedTeam->forceFill(['is_active' => false])->save();
            $lockedTeam->delete();

            $this->auditLog->execute(
                $deletedBy,
                'team.delete',
                $lockedTeam,
                oldValues: ['is_active' => $wasActive],
                newValues: [
                    'deleted' => true,
                    'deactivated_member_ids' => $activeMemberIds,
                    'revoked_upcoming_invitations' => $revokedInvitations,
                ],
            );
        });
    }
}

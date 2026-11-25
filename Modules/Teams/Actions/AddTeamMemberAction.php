<?php

namespace Modules\Teams\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Teams\Models\Team;
use Modules\Teams\Models\TeamMember;

class AddTeamMemberAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLog) {}

    public function execute(Team $team, User|int $member, User $addedBy, string $teamRole = 'member'): TeamMember
    {
        if (! in_array($teamRole, ['lead', 'member'], true)) {
            throw ValidationException::withMessages(['team_role' => ['The selected team role is invalid.']]);
        }

        $memberId = $member instanceof User ? (int) $member->getKey() : $member;

        return DB::transaction(function () use ($team, $memberId, $addedBy, $teamRole): TeamMember {
            $lockedTeam = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($addedBy)->authorize('manageMembers', $lockedTeam);

            $activeUser = User::query()
                ->whereKey($memberId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $activeUser) {
                throw ValidationException::withMessages([
                    'user_id' => ['Only active users can be added to a team.'],
                ]);
            }

            $membership = DB::table('team_members')
                ->where('team_id', $lockedTeam->getKey())
                ->where('user_id', $activeUser->getKey())
                ->lockForUpdate()
                ->first();

            $isOwner = (int) $lockedTeam->owner_user_id === (int) $activeUser->getKey();

            if ($isOwner && $teamRole !== 'lead') {
                throw ValidationException::withMessages([
                    'team_role' => ['The team owner must remain an active team lead.'],
                ]);
            }

            if (
                $membership?->membership_status === 'active'
                && $membership->team_role === 'lead'
                && $teamRole !== 'lead'
            ) {
                $activeLeadCount = DB::table('team_members')
                    ->where('team_id', $lockedTeam->getKey())
                    ->where('membership_status', 'active')
                    ->where('team_role', 'lead')
                    ->count();

                if ($activeLeadCount <= 1) {
                    throw ValidationException::withMessages([
                        'team_role' => ['Assign another active team lead before demoting the last lead.'],
                    ]);
                }
            }

            if ($membership?->membership_status === 'active' && $membership->team_role === $teamRole) {
                return $lockedTeam->memberships()->where('user_id', $activeUser->getKey())->firstOrFail();
            }

            DB::table('team_members')->updateOrInsert(
                ['team_id' => $lockedTeam->getKey(), 'user_id' => $activeUser->getKey()],
                [
                    'team_role' => $teamRole,
                    'membership_status' => 'active',
                    'added_by_user_id' => $addedBy->getKey(),
                    'joined_at' => now(),
                    'left_at' => null,
                ],
            );

            $this->auditLog->execute(
                $addedBy,
                'team.member.assign',
                $lockedTeam,
                oldValues: $membership ? [
                    'membership_status' => $membership->membership_status,
                    'team_role' => $membership->team_role,
                ] : [],
                newValues: ['user_id' => $activeUser->getKey(), 'membership_status' => 'active', 'team_role' => $teamRole],
            );

            return $lockedTeam->memberships()->where('user_id', $activeUser->getKey())->firstOrFail();
        });
    }
}

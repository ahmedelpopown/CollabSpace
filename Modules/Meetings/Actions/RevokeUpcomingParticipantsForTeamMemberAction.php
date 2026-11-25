<?php

namespace Modules\Meetings\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Meetings\Models\Meeting;

class RevokeUpcomingParticipantsForTeamMemberAction
{
    public function execute(int $teamId, int $userId): int
    {
        $meetingIds = Meeting::query()
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->whereHas('project.teamAssignments', fn ($query) => $query->where('team_id', $teamId))
            ->select('id');

        return DB::table('meeting_participants')
            ->whereIn('meeting_id', $meetingIds)
            ->where('user_id', $userId)
            ->where('eligible_team_id', $teamId)
            ->whereNull('access_revoked_at')
            ->update(['access_revoked_at' => now()]);
    }
}

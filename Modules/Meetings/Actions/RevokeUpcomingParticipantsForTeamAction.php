<?php

namespace Modules\Meetings\Actions;

use Illuminate\Support\Facades\DB;

class RevokeUpcomingParticipantsForTeamAction
{
    public function execute(int $teamId): int
    {
        $upcomingMeetingIds = DB::table('meetings')
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->select('id');

        return DB::table('meeting_participants')
            ->whereIn('meeting_id', $upcomingMeetingIds)
            ->where('eligible_team_id', $teamId)
            ->whereNull('access_revoked_at')
            ->update(['access_revoked_at' => now()]);
    }
}

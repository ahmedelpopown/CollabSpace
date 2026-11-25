<?php

namespace Modules\Meetings\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Meetings\Models\Meeting;

class RevokeUpcomingParticipantsForProjectTeamAction
{
    public function execute(int $projectId, int $teamId): int
    {
        $upcomingMeetingIds = Meeting::query()
            ->where('project_id', $projectId)
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

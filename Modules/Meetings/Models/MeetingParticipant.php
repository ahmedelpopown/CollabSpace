<?php

namespace Modules\Meetings\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Models\User;
use Modules\Teams\Models\Team;

class MeetingParticipant extends Pivot
{
    protected $table = 'meeting_participants';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'meeting_id', 'user_id', 'eligible_team_id', 'invited_by_user_id',
        'response_status', 'eligibility_checked_at', 'access_revoked_at',
        'joined_at', 'left_at', 'invited_at',
    ];

    protected function casts(): array
    {
        return [
            'eligibility_checked_at' => 'datetime',
            'access_revoked_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'invited_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function eligibleTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'eligible_team_id');
    }

    public function hasCurrentEligibility(): bool
    {
        if ($this->access_revoked_at || $this->response_status === 'declined') {
            return false;
        }

        $meeting = $this->meeting;

        if (! $meeting || $meeting->cancelled_at || $meeting->status === 'cancelled' || ! $meeting->project) {
            return false;
        }

        $eligible = DB::table('project_teams')
            ->join('team_members', 'team_members.team_id', '=', 'project_teams.team_id')
            ->join('teams', 'teams.id', '=', 'project_teams.team_id')
            ->join('users', 'users.id', '=', 'team_members.user_id')
            ->where('project_teams.project_id', $meeting->project_id)
            ->where('project_teams.team_id', $this->eligible_team_id)
            ->where('team_members.team_id', $this->eligible_team_id)
            ->where('team_members.user_id', $this->user_id)
            ->where('team_members.membership_status', 'active')
            ->where('teams.is_active', true)
            ->whereNull('teams.deleted_at')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->exists();

        if ($eligible) {
            return true;
        }

        if ($meeting->starts_at->isFuture()) {
            DB::table('meeting_participants')
                ->where('meeting_id', $this->meeting_id)
                ->where('user_id', $this->user_id)
                ->whereNull('access_revoked_at')
                ->update(['access_revoked_at' => now()]);
        }

        return false;
    }

    public static function revokeUpcomingForTeamMember(int $teamId, int $userId): void
    {
        $meetingIds = Meeting::query()
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->whereHas('project.teamAssignments', fn ($query) => $query->where('team_id', $teamId))
            ->select('id');

        DB::table('meeting_participants')
            ->whereIn('meeting_id', $meetingIds)
            ->where('user_id', $userId)
            ->where('eligible_team_id', $teamId)
            ->whereNull('access_revoked_at')
            ->update(['access_revoked_at' => now()]);
    }

    public static function revokeUpcomingForProjectTeam(int $projectId, int $teamId): void
    {
        $meetingIds = Meeting::query()
            ->where('project_id', $projectId)
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->select('id');

        DB::table('meeting_participants')
            ->whereIn('meeting_id', $meetingIds)
            ->where('eligible_team_id', $teamId)
            ->whereNull('access_revoked_at')
            ->update(['access_revoked_at' => now()]);
    }
}

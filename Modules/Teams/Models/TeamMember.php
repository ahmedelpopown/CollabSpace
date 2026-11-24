<?php

namespace Modules\Teams\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Models\AuditLog;
use Modules\Identity\Models\User;
use Modules\Meetings\Models\MeetingParticipant;

class TeamMember extends Pivot
{
    protected $table = 'team_members';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'team_id', 'user_id', 'team_role', 'membership_status',
        'added_by_user_id', 'joined_at', 'left_at',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function endMembership(User $endedBy): void
    {
        if (! $endedBy->exists
            || ! $endedBy->is_active
            || $endedBy->trashed()
            || ! $endedBy->hasRole('Administrator')
            || ! $endedBy->can('teams.members.assign')) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'The user cannot end team memberships.',
            );
        }

        DB::transaction(function () use ($endedBy): void {
            $membership = DB::table('team_members')
                ->where('team_id', $this->team_id)
                ->where('user_id', $this->user_id)
                ->lockForUpdate()
                ->first();

            if (! $membership || $membership->membership_status !== 'active') {
                return;
            }

            DB::table('team_members')
                ->where('team_id', $this->team_id)
                ->where('user_id', $this->user_id)
                ->update(['membership_status' => 'inactive', 'left_at' => now()]);

            MeetingParticipant::revokeUpcomingForTeamMember((int) $this->team_id, (int) $this->user_id);

            AuditLog::create([
                'actor_user_id' => $endedBy->getKey(),
                'action' => 'team.member.remove',
                'subject_type' => Team::class,
                'subject_id' => $this->team_id,
                'old_values' => [
                    'user_id' => $this->user_id,
                    'team_role' => $membership->team_role,
                    'membership_status' => $membership->membership_status,
                ],
                'new_values' => ['membership_status' => 'inactive'],
            ]);
        });
    }
}

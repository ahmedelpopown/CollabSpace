<?php

namespace Modules\Meetings\Models;

use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

/**
 * @property int $project_id
 * @property Project $project
 */
class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'created_by_user_id', 'title', 'description',
        'starts_at', 'ends_at', 'meeting_url', 'status', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Meetings\Factories\MeetingFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function participantRecords(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_participants', 'meeting_id', 'user_id')
            ->using(MeetingParticipant::class)
            ->withPivot([
                'eligible_team_id', 'invited_by_user_id', 'response_status',
                'eligibility_checked_at', 'access_revoked_at', 'joined_at', 'left_at', 'invited_at',
            ]);
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(MeetingSummary::class);
    }

    public function inviteEligibleMember(User $user, User $invitedBy): MeetingParticipant
    {
        $project = $this->project;

        if (! $this->exists || ! $project || $this->cancelled_at || $this->status === 'cancelled') {
            throw new \DomainException('Participants cannot be invited to an unavailable or cancelled meeting.');
        }

        if (! Gate::forUser($invitedBy)->allows('update', $project) || ! $invitedBy->can('meetings.invite')) {
            throw new AuthorizationException('The user cannot invite participants to this project meeting.');
        }

        if (! $user->exists || ! $user->is_active || $user->trashed()) {
            throw new \DomainException('Only active users may be invited to a meeting.');
        }

        $eligibleTeamId = DB::table('project_teams')
            ->join('team_members', 'team_members.team_id', '=', 'project_teams.team_id')
            ->join('teams', 'teams.id', '=', 'project_teams.team_id')
            ->join('users', 'users.id', '=', 'team_members.user_id')
            ->where('project_teams.project_id', $this->project_id)
            ->where('team_members.user_id', $user->getKey())
            ->where('team_members.membership_status', 'active')
            ->where('teams.is_active', true)
            ->whereNull('teams.deleted_at')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->orderBy('project_teams.team_id')
            ->value('project_teams.team_id');

        if ($eligibleTeamId === null) {
            throw new \DomainException('Meeting invitees must be active members of a team assigned to the project.');
        }

        DB::transaction(function () use ($user, $invitedBy, $eligibleTeamId): void {
            DB::table('meeting_participants')->updateOrInsert(
                ['meeting_id' => $this->getKey(), 'user_id' => $user->getKey()],
                [
                    'eligible_team_id' => $eligibleTeamId,
                    'invited_by_user_id' => $invitedBy->getKey(),
                    'response_status' => 'pending',
                    'eligibility_checked_at' => now(),
                    'access_revoked_at' => null,
                    'invited_at' => now(),
                ],
            );
        });

        return $this->participantRecords()->where('user_id', $user->getKey())->firstOrFail();
    }

    public function canBeAccessedBy(User $user): bool
    {
        $project = $this->project;

        if (! $this->exists || $this->cancelled_at || $this->status === 'cancelled'
            || ! $project
            || ! $user->exists || ! $user->is_active || $user->trashed()
            || ! $user->can('meetings.view')) {
            return false;
        }

        if ($user->hasRole('Administrator') || $user->hasRole('Project Manager')) {
            return Gate::forUser($user)->allows('view', $project);
        }

        if (! $user->can('meetings.attend')) {
            return false;
        }

        $participant = MeetingParticipant::query()
            ->where('meeting_id', $this->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        return $participant?->hasCurrentEligibility() ?? false;
    }
}

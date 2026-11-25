<?php

namespace Modules\Teams\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Identity\Models\User;
use Modules\Meetings\Models\MeetingParticipant;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTeam;
use Modules\Tasks\Models\Task;

/** @property bool $is_active */
class Team extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'owner_user_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Teams\Factories\TeamFactory::new();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(TeamMember::class)
            ->withPivot(['team_role', 'membership_status', 'added_by_user_id', 'joined_at', 'left_at']);
    }

    public function activeMembers(): BelongsToMany
    {
        return $this->members()
            ->wherePivot('membership_status', TeamMember::STATUS_ACTIVE)
            ->where('users.is_active', true);
    }

    public function projectAssignments(): HasMany
    {
        return $this->hasMany(ProjectTeam::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_teams', 'team_id', 'project_id')
            ->using(ProjectTeam::class)
            ->withPivot(['assigned_by_user_id', 'assigned_at']);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_team_id');
    }

    public function meetingParticipants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class, 'eligible_team_id');
    }
}

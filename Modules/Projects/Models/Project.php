<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Models\AuditLog;
use Modules\Identity\Models\User;
use Modules\Files\Models\File;
use Modules\Meetings\Models\Meeting;
use Modules\Tasks\Models\Task;
use Modules\Teams\Models\Team;
use Modules\Meetings\Models\MeetingParticipant;

/**
 * @property \Illuminate\Support\Carbon|null $deadline
 * @property string $status
 */
class Project extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'description', 'created_by_user_id', 'starts_on', 'deadline', 'priority', 'status',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'deadline' => 'date'];
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Projects\Factories\ProjectFactory::new();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members', 'project_id', 'user_id')
            ->using(ProjectMember::class)
            ->withPivot(['membership_status', 'added_by_user_id', 'invited_at', 'joined_at', 'removed_at']);
    }

    public function activeMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('membership_status', 'active')->where('users.is_active', true);
    }

    public function teamAssignments(): HasMany
    {
        return $this->hasMany(ProjectTeam::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'project_teams', 'project_id', 'team_id')
            ->using(ProjectTeam::class)
            ->withPivot(['assigned_by_user_id', 'assigned_at']);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'project_files', 'project_id', 'file_id')
            ->using(\Modules\Files\Models\ProjectFile::class)
            ->withPivot(['attached_by_user_id', 'attached_at']);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(\Modules\Chat\Models\Message::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public static function createWithCreatorMembership(User $creator, array $attributes): static
    {
        if (! $creator->exists || ! $creator->is_active || $creator->trashed()
            || ! $creator->can('projects.create')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The user cannot create projects.');
        }

        return DB::transaction(function () use ($creator, $attributes) {
            $project = static::create([
                ...$attributes,
                'created_by_user_id' => $creator->getKey(),
            ]);

            $project->members()->attach($creator->getKey(), [
                'membership_status' => 'active',
                'added_by_user_id' => $creator->getKey(),
                'joined_at' => now(),
            ]);

            AuditLog::create([
                'actor_user_id' => $creator->getKey(),
                'action' => 'project.create',
                'subject_type' => static::class,
                'subject_id' => $project->getKey(),
                'new_values' => ['creator_user_id' => $creator->getKey()],
            ]);

            return $project;
        });
    }

    public function assignTeam(Team $team, User $assignedBy): void
    {
        if (! $team->exists || ! $team->is_active || $team->trashed()) {
            throw new \DomainException('Only active teams may be assigned to a project.');
        }

        if (! $this->canBeManagedBy($assignedBy) || ! $assignedBy->can('projects.teams.assign')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The user cannot assign teams to this project.');
        }

        DB::transaction(function () use ($team, $assignedBy): void {
            $this->teams()->syncWithoutDetaching([
                $team->getKey() => [
                    'assigned_by_user_id' => $assignedBy->getKey(),
                    'assigned_at' => now(),
                ],
            ]);

            AuditLog::create([
                'actor_user_id' => $assignedBy->getKey(),
                'action' => 'project.team.assign',
                'subject_type' => static::class,
                'subject_id' => $this->getKey(),
                'new_values' => ['team_id' => $team->getKey()],
            ]);
        });
    }

    public function addMember(User $member, User $addedBy): void
    {
        if (! $member->exists || ! $member->is_active || $member->trashed()) {
            throw new \DomainException('Inactive users cannot be added to a project.');
        }

        if (! $this->canBeManagedBy($addedBy) || ! $addedBy->can('projects.members.assign')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The user cannot assign members to this project.');
        }

        DB::transaction(function () use ($member, $addedBy): void {
            DB::table('project_members')->updateOrInsert(
                ['project_id' => $this->getKey(), 'user_id' => $member->getKey()],
                [
                    'membership_status' => 'active',
                    'added_by_user_id' => $addedBy->getKey(),
                    'joined_at' => now(),
                    'removed_at' => null,
                ],
            );

            AuditLog::create([
                'actor_user_id' => $addedBy->getKey(),
                'action' => 'project.member.assign',
                'subject_type' => static::class,
                'subject_id' => $this->getKey(),
                'new_values' => ['user_id' => $member->getKey()],
            ]);
        });
    }

    public function removeMember(User $member, User $removedBy): void
    {
        if (! $member->exists) {
            throw new \DomainException('The project member does not exist.');
        }

        if (! $this->canBeManagedBy($removedBy) || ! $removedBy->can('projects.members.assign')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The user cannot remove members from this project.');
        }

        DB::transaction(function () use ($member, $removedBy): void {
            $membership = DB::table('project_members')
                ->where('project_id', $this->getKey())
                ->where('user_id', $member->getKey())
                ->lockForUpdate()
                ->first();

            if (! $membership || $membership->membership_status !== 'active') {
                throw new \DomainException('The user is not an active member of this project.');
            }

            DB::table('project_members')
                ->where('project_id', $this->getKey())
                ->where('user_id', $member->getKey())
                ->update([
                    'membership_status' => 'removed',
                    'removed_at' => now(),
                ]);

            AuditLog::create([
                'actor_user_id' => $removedBy->getKey(),
                'action' => 'project.member.remove',
                'subject_type' => static::class,
                'subject_id' => $this->getKey(),
                'old_values' => ['user_id' => $member->getKey(), 'membership_status' => 'active'],
                'new_values' => ['membership_status' => 'removed'],
            ]);
        });
    }

    public function detachTeam(Team $team, User $detachedBy): void
    {
        if (! $team->exists) {
            throw new \DomainException('The team does not exist.');
        }

        if (! $this->canBeManagedBy($detachedBy) || ! $detachedBy->can('projects.teams.assign')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The user cannot remove teams from this project.');
        }

        DB::transaction(function () use ($team, $detachedBy): void {
            $assignmentExists = $this->teamAssignments()
                ->where('team_id', $team->getKey())
                ->lockForUpdate()
                ->exists();

            if (! $assignmentExists) {
                throw new \DomainException('The team is not assigned to this project.');
            }

            MeetingParticipant::revokeUpcomingForProjectTeam((int) $this->getKey(), (int) $team->getKey());
            $this->teams()->detach($team->getKey());

            AuditLog::create([
                'actor_user_id' => $detachedBy->getKey(),
                'action' => 'project.team.detach',
                'subject_type' => static::class,
                'subject_id' => $this->getKey(),
                'old_values' => ['team_id' => $team->getKey()],
            ]);
        });
    }

    public function canBeManagedBy(User $user): bool
    {
        return $this->exists
            && ! $this->trashed()
            && $user->can('projects.update')
            && $user->canAccessProject($this);
    }

    public function eligibleUsersFromTeam(int $teamId): \Illuminate\Database\Eloquent\Builder
    {
        $query = User::query()
            ->join('team_members', 'team_members.user_id', '=', 'users.id')
            ->join('project_teams', 'project_teams.team_id', '=', 'team_members.team_id')
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->where('project_teams.project_id', $this->getKey())
            ->where('team_members.team_id', $teamId)
            ->where('team_members.membership_status', 'active')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->where('teams.is_active', true)
            ->whereNull('teams.deleted_at')
            ->select('users.*');

        return $this->exists && ! $this->trashed()
            ? $query
            : $query->whereRaw('1 = 0');
    }

    public function teamIsAssigned(int $teamId): bool
    {
        return $this->teamAssignments()->where('team_id', $teamId)->exists();
    }

    public function canBeAccessedBy(User $user): bool
    {
        return $user->canAccessProject($this);
    }

    public function completionPercentage(): float
    {
        $totalTasks = $this->tasks()->count();

        if ($totalTasks === 0) {
            return 0.0;
        }

        $completedTasks = $this->tasks()->where('status', 'completed')->count();

        return round(($completedTasks / $totalTasks) * 100, 2);
    }

    public function isDelayed(): bool
    {
        return $this->deadline !== null
            && $this->deadline->lt(today())
            && ! \in_array($this->status, ['completed', 'cancelled'], true);
    }
}

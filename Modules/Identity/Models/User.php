<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Identity\Notifications\PasswordResetNotification;
use Modules\AI\Models\AIActivityLog;
use Modules\AI\Models\AIConversation;
use Modules\Files\Models\File as StoredFile;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingParticipant;
use Modules\Notifications\Models\Notification;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectMember;
use Modules\Reports\Models\ReportExport;
use Modules\Tasks\Models\TaskAssignee;
use Modules\Teams\Models\Team;
use Modules\Teams\Models\TeamMember;
use Modules\Chat\Models\Message;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property bool $is_active */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $guard_name = 'web';

    protected $fillable = [
        'full_name',
        'email',
        'password',
        'job_title',
        'years_experience',
        'avatar_path',
        'email_verified_at',
        'is_active',
        'last_seen_at',
    ];

    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            $user->tokens()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'years_experience' => 'integer',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetNotification($token));
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Identity\Factories\UserFactory::new();
    }

    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OAuthAccount::class);
    }

    public function authenticationLogs(): HasMany
    {
        return $this->hasMany(AuthenticationLog::class);
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members', 'user_id', 'team_id')
            ->using(TeamMember::class)
            ->withPivot(['team_role', 'membership_status', 'added_by_user_id', 'joined_at', 'left_at']);
    }

    public function activeTeams(): BelongsToMany
    {
        return $this->teams()->wherePivot('membership_status', 'active')->where('teams.is_active', true);
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members', 'user_id', 'project_id')
            ->using(ProjectMember::class)
            ->withPivot(['membership_status', 'added_by_user_id', 'invited_at', 'joined_at', 'removed_at']);
    }

    public function activeProjects(): BelongsToMany
    {
        return $this->projects()->wherePivot('membership_status', 'active');
    }

    public function taskAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    public function meetingInvitations(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_user_id');
    }

    public function uploadedFiles(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'uploaded_by_user_id');
    }

    public function meetingsCreated(): HasMany
    {
        return $this->hasMany(Meeting::class, 'created_by_user_id');
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AIConversation::class, 'created_by_user_id');
    }

    public function aiActivityLogs(): HasMany
    {
        return $this->hasMany(AIActivityLog::class);
    }

    public function reportExports(): HasMany
    {
        return $this->hasMany(ReportExport::class, 'created_by_user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function hasActiveProjectMembership(int $projectId): bool
    {
        return $this->is_active && ! $this->trashed() && $this->projectMemberships()
            ->where('project_id', $projectId)
            ->where('membership_status', 'active')
            ->exists();
    }

    public function canAccessProject(Project $project): bool
    {
        if (! $this->exists
            || ! $this->is_active
            || $this->trashed()
            || ! $project->exists
            || $project->trashed()
            || ! $this->can('projects.view')) {
            return false;
        }

        return $this->hasRole('Administrator')
            || $this->hasActiveProjectMembership((int) $project->getKey());
    }
}

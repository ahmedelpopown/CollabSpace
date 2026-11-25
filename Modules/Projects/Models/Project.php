<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Meetings\Models\Meeting;
use Modules\Tasks\Models\Task;
use Modules\Teams\Models\Team;

/**
 * @property \Illuminate\Support\Carbon|null $deadline
 * @property string $status
 */
class Project extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'name',
        'description',
        'starts_on',
        'deadline',
        'priority',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'deadline' => 'date',
        ];
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
        return $this->members()
            ->wherePivot('membership_status', ProjectMember::STATUS_ACTIVE)
            ->where('users.is_active', true);
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

    /**
     * This calculation only uses the current model state, so it has no database side effects.
     */
    public function isDelayed(): bool
    {
        return $this->deadline !== null
            && $this->deadline->lt(today())
            && ! in_array($this->status, ['completed', 'cancelled'], true);
    }
}

<?php

namespace Modules\Files\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

class File extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'original_name',
    ];

    protected $hidden = ['storage_disk', 'storage_path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function setOriginalNameAttribute(string $value): void
    {
        $safeName = basename(str_replace('\\', '/', trim($value)));
        $safeName = preg_replace('/[\x00-\x1F\x7F]/', '', $safeName) ?? '';

        $this->attributes['original_name'] = substr($safeName, 0, 255);
    }


    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Files\Factories\FileFactory::new();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_files', 'file_id', 'project_id')
            ->using(ProjectFile::class)
            ->withPivot(['attached_by_user_id', 'attached_at']);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_files', 'file_id', 'task_id')
            ->using(TaskFile::class)
            ->withPivot(['attached_by_user_id', 'attached_at']);
    }

    public function messageAttachments(): HasMany
    {
        return $this->hasMany(\Modules\Chat\Models\MessageAttachment::class);
    }

    public function canBeAccessedBy(User $user): bool
    {
        if (! $this->exists || $this->trashed()
            || ! $user->exists || ! $user->is_active || $user->trashed()
            || ! $user->can('files.view')) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        $activeProjectMembership = fn ($members) => $members
            ->where('user_id', $user->getKey())
            ->where('membership_status', 'active');

        return $this->projects()->whereHas('memberships', $activeProjectMembership)->exists()
            || $this->tasks()->whereHas('project.memberships', $activeProjectMembership)->exists()
            || $this->messageAttachments()
                ->whereHas('message.project.memberships', $activeProjectMembership)
                ->exists();
    }

    public function canBeUploadedToBy(User $user, \Modules\Projects\Models\Project $project): bool
    {
        return $this->exists
            && ! $this->trashed()
            && $user->can('files.upload')
            && $user->canAccessProject($project);
    }
}

<?php

namespace Modules\Chat\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

class Message extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['project_id', 'sender_user_id', 'parent_message_id', 'body', 'edited_at', 'deleted_at'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Chat\Factories\MessageFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_message_id');
    }

    public function mentionRecords(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_mentions', 'message_id', 'mentioned_user_id')
            ->using(MessageMention::class)
            ->withPivot('created_at');
    }

    public function readRecords(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_reads', 'message_id', 'user_id')
            ->using(MessageRead::class)
            ->withPivot('read_at');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'message_attachments', 'message_id', 'file_id')
            ->using(MessageAttachment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function canBeReadBy(User $user): bool
    {
        $project = $this->project;

        return $this->exists
            && ! $this->trashed()
            && $project !== null
            && $user->can('chat.read')
            && $user->canAccessProject($project);
    }

    public static function sendForProjectMember(User $sender, Project $project, string $body, ?self $parent = null): self
    {
        if (! $sender->can('chat.send') || ! $sender->canAccessProject($project)) {
            throw new AuthorizationException('Active project membership is required to use project chat.');
        }

        $body = trim($body);

        if ($body === '' || strlen($body) > 40000) {
            throw new \InvalidArgumentException('A chat message must contain between 1 and 40000 bytes.');
        }

        if ($parent && (
            ! $parent->exists
            || $parent->trashed()
            || (int) $parent->project_id !== (int) $project->getKey()
            || ! $parent->canBeReadBy($sender)
        )) {
            throw new \DomainException('A reply must belong to the same accessible project and an active parent message.');
        }

        return DB::transaction(fn () => static::create([
            'project_id' => $project->getKey(),
            'sender_user_id' => $sender->getKey(),
            'parent_message_id' => $parent?->getKey(),
            'body' => $body,
        ]));
    }

    public function addMention(User $actor, User $mentionedUser): void
    {
        $project = $this->project;

        if (! $this->exists || $this->trashed()
            || ! $project
            || ! $actor->can('chat.send')
            || ! $actor->canAccessProject($project)) {
            throw new AuthorizationException('The user cannot add mentions to this project message.');
        }

        if (! $mentionedUser->exists
            || ! $mentionedUser->is_active
            || $mentionedUser->trashed()
            || ! $mentionedUser->can('chat.read')
            || ! $mentionedUser->canAccessProject($project)) {
            throw new \DomainException('Only active users with project chat access can be mentioned.');
        }

        $this->mentions()->syncWithoutDetaching([$mentionedUser->getKey()]);
    }

    public function attachFile(File $file, User $attachedBy): void
    {
        $project = $this->project;

        if (! $this->exists || $this->trashed()
            || ! $project
            || ! $attachedBy->can('chat.send')
            || ! $attachedBy->can('files.upload')
            || ! $attachedBy->canAccessProject($project)) {
            throw new AuthorizationException('The user cannot attach files to this project message.');
        }

        if (! $file->exists || $file->trashed()) {
            throw new \DomainException('The file does not exist or is no longer available.');
        }

        $isUploader = (int) $file->uploaded_by_user_id === (int) $attachedBy->getKey();

        if (! $isUploader && ! $file->canBeAccessedBy($attachedBy)) {
            throw new AuthorizationException('The user cannot share a file they cannot access.');
        }

        DB::transaction(function () use ($file): void {
            $this->files()->syncWithoutDetaching([$file->getKey()]);
        });
    }

    public function markReadBy(User $user): void
    {
        if (! $this->canBeReadBy($user)) {
            throw new AuthorizationException('Only users with project chat access can mark a message read.');
        }

        $this->readers()->syncWithoutDetaching([$user->getKey() => ['read_at' => now()]]);
    }
}

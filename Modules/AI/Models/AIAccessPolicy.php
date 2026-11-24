<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Audit\Models\AuditLog;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Modules\Identity\Models\User;

/** @property string $access_scope */
class AIAccessPolicy extends Model
{
    private const SCOPE_RESOURCES = [
        'own' => ['tasks', 'reports'],
        'assigned_teams' => ['teams'],
        'assigned_projects' => ['projects', 'tasks', 'meetings', 'reports', 'file_metadata'],
        'invited_meetings' => ['meetings'],
        'organization' => ['projects', 'tasks', 'teams', 'meetings', 'reports', 'file_metadata'],
    ];

    protected $table = 'ai_access_policies';

    protected $fillable = [
        'role_id', 'resource_type', 'action', 'access_scope', 'is_enabled', 'configured_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }

    public static function configureBy(User $actor, array $attributes): self
    {
        if (! $actor->exists
            || ! $actor->is_active
            || $actor->trashed()
            || ! $actor->hasRole('Administrator')
            || ! $actor->can('ai.configure')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Only an Administrator may configure AI access policies.');
        }

        $validated = Validator::make($attributes, [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'resource_type' => ['required', 'string', 'in:projects,tasks,teams,meetings,reports,file_metadata'],
            'action' => ['required', 'string', 'in:read,search,summarize,analyze,generate'],
            'access_scope' => ['required', 'string', 'in:own,assigned_teams,assigned_projects,invited_meetings,organization'],
            'is_enabled' => ['sometimes', 'boolean'],
        ])->validate();

        if (! in_array($validated['resource_type'], self::SCOPE_RESOURCES[$validated['access_scope']], true)) {
            throw ValidationException::withMessages([
                'access_scope' => 'The selected access scope is not supported for this resource.',
            ]);
        }

        $role = Role::query()->findOrFail($validated['role_id']);

        if ($role->guard_name !== 'web') {
            throw ValidationException::withMessages([
                'role_id' => 'AI access policies must use an application role with the web guard.',
            ]);
        }

        if ($validated['access_scope'] === 'organization' && $role->name !== 'Administrator') {
            throw ValidationException::withMessages([
                'access_scope' => 'Organization-wide AI access is reserved for the Administrator role.',
            ]);
        }

        return DB::transaction(function () use ($actor, $validated) {
            $policy = static::query()->updateOrCreate(
                [
                    'role_id' => $validated['role_id'],
                    'resource_type' => $validated['resource_type'],
                    'action' => $validated['action'],
                    'access_scope' => $validated['access_scope'],
                ],
                [
                    'is_enabled' => $validated['is_enabled'] ?? false,
                    'configured_by_user_id' => $actor->getKey(),
                ],
            );

            AuditLog::create([
                'actor_user_id' => $actor->getKey(),
                'action' => 'ai.access_policy.configure',
                'subject_type' => static::class,
                'subject_id' => $policy->getKey(),
                'new_values' => $policy->only(['role_id', 'resource_type', 'action', 'access_scope', 'is_enabled']),
            ]);

            return $policy;
        });
    }

}

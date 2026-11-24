<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Audit\Models\AuditLog;
use Modules\Identity\Models\User;

class AISetting extends Model
{
    protected $table = 'ai_settings';

    protected $fillable = ['is_enabled', 'provider', 'default_model', 'temperature', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'temperature' => 'decimal:2'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public static function configureBy(User $actor, array $attributes): self
    {
        if (! $actor->exists
            || ! $actor->is_active
            || $actor->trashed()
            || ! $actor->hasRole('Administrator')
            || ! $actor->can('ai.configure')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Only an Administrator may configure AI settings.');
        }

        $validated = Validator::make($attributes, [
            'is_enabled' => ['sometimes', 'boolean'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:80'],
            'default_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated) {
            $setting = static::query()->firstOrNew(['id' => 1]);
            if (! $setting->exists && ! array_key_exists('temperature', $validated)) {
                $setting->temperature = 0.70;
            }
            $setting->fill($validated);
            $setting->updated_by_user_id = $actor->getKey();
            $setting->save();

            AuditLog::create([
                'actor_user_id' => $actor->getKey(),
                'action' => 'ai.settings.configure',
                'subject_type' => static::class,
                'subject_id' => $setting->getKey(),
                'new_values' => $setting->only(['is_enabled', 'provider', 'default_model', 'temperature']),
            ]);

            return $setting;
        });
    }
}

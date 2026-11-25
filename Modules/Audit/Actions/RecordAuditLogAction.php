<?php

namespace Modules\Audit\Actions;

use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Models\AuditLog;
use Modules\Identity\Models\User;

class RecordAuditLogAction
{
    public function execute(
        User $actor,
        string $action,
        Model $subject,
        array $oldValues = [],
        array $newValues = [],
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'created_at' => now(),
        ]);
    }
}

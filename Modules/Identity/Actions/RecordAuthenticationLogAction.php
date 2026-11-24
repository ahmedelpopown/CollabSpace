<?php

namespace Modules\Identity\Actions;

use Modules\Identity\Models\AuthenticationLog;
use Modules\Identity\Models\User;

class RecordAuthenticationLogAction
{
    public function execute(
        ?User $user,
        string $attemptedEmail,
        string $loginMethod,
        bool $wasSuccessful,
        ?string $failureReason,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        AuthenticationLog::query()->create([
            'user_id' => $user?->getKey(),
            'attempted_email' => mb_strtolower(trim($attemptedEmail)),
            'login_method' => $loginMethod,
            'was_successful' => $wasSuccessful,
            'failure_reason' => $failureReason,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }
}
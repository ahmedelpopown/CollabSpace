<?php

namespace Modules\Identity\Actions;

use Modules\Identity\Models\User;
use Modules\Identity\Services\Auth\AccessTokenService;

final class LogoutAllUserSessionsAction
{
    public function __construct(
        private readonly AccessTokenService $accessTokens,
    ) {}

    public function execute(User $user): int
    {
        return $this->accessTokens->revokeAll($user);
    }
}
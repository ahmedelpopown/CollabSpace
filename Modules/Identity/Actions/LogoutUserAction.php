<?php

namespace Modules\Identity\Actions;

use Modules\Identity\Models\User;
use Modules\Identity\Services\Auth\AccessTokenService;

final class LogoutUserAction
{
    public function __construct(
        private readonly AccessTokenService $accessTokens,
    ) {}

    public function execute(User $user): void
    {
        $this->accessTokens->revokeCurrent($user);
    }
}
<?php

namespace Modules\Identity\Actions;

use Modules\Identity\DTOs\AuthenticatedSessionData;
use Modules\Identity\Models\User;
use Modules\Identity\Services\Auth\AccessTokenService;

final class IssueAccessTokenAction
{
    public function __construct(
        private readonly AccessTokenService $accessTokens,
    ) {}

    public function execute(
        User $user,
        bool $rememberMe = false,
        ?string $deviceName = null,
    ): AuthenticatedSessionData {
        $issuedToken = $this->accessTokens->issue(
            user: $user,
            rememberMe: $rememberMe,
            deviceName: $deviceName,
        );

        return new AuthenticatedSessionData(
            user: $user,
            accessToken: $issuedToken->accessToken,
            tokenType: $issuedToken->tokenType,
            expiresAt: $issuedToken->expiresAt,
        );
    }
}
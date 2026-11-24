<?php

namespace Modules\Identity\Services\Auth;

use LogicException;
use Modules\Identity\DTOs\IssuedAccessTokenData;
use Modules\Identity\Models\User;

final class AccessTokenService
{
    public function issue(
        User $user,
        bool $rememberMe = false,
        ?string $deviceName = null,
    ): IssuedAccessTokenData {
        $configKey = $rememberMe
            ? 'identity.remember_token_expiration_minutes'
            : 'identity.token_expiration_minutes';

        $minutes = config($configKey);

        if (! is_numeric($minutes) || (int) $minutes < 1) {
            throw new LogicException(
                "Invalid or missing token expiration configuration [{$configKey}]."
            );
        }

        $tokenName = trim((string) $deviceName);
        $tokenName = $tokenName !== '' ? mb_substr($tokenName, 0, 100) : 'CollabSpace API';
        $expiresAt = now()->addMinutes((int) $minutes);

        $newToken = $user->createToken(
            $tokenName,
            ['*'],
            $expiresAt,
        );

        return new IssuedAccessTokenData(
            accessToken: $newToken->plainTextToken,
            tokenType: 'Bearer',
            expiresAt: $expiresAt->toISOString(),
        );
    }

    public function revokeCurrent(User $user): void
    {
        $currentToken = $user->currentAccessToken();

        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }
    }

    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }
}
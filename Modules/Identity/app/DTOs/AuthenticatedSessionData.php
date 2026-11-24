<?php

namespace Modules\Identity\DTOs;

use Modules\Identity\Models\User;

final readonly class AuthenticatedSessionData
{
    public function __construct(
        public User $user,
        public string $accessToken,
        public string $tokenType,
        public string $expiresAt,
    ) {}
}
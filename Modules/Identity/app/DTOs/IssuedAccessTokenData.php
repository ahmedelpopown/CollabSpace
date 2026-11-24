<?php

namespace Modules\Identity\DTOs;

final readonly class IssuedAccessTokenData
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public string $expiresAt,
    ) {}
}
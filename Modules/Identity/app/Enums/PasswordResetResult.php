<?php

namespace Modules\Identity\Enums;

enum PasswordResetResult: string
{
    case Success = 'success';
    case Invalid = 'invalid';
    case Throttled = 'throttled';

    public function successful(): bool
    {
        return $this === self::Success;
    }
}
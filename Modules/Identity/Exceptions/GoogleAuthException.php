<?php

namespace Modules\Identity\Exceptions;

use RuntimeException;

class GoogleAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 400,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function providerFailed(?\Throwable $previous = null): self
    {
        return new self(
            message: 'Unable to sign in using Google. Please try again',
            statusCode: 401,
            previous: $previous,
        );
    }

    public static function unverifiedAccount(): self
    {
        return new self(
            message: 'Unable to verify the Google account or email',
            statusCode: 422,
        );
    }

    public static function inactiveAccount(): self
    {
        return new self(
            message: 'This account is inactive or suspended.',
            statusCode: 403,
        );
    }

    public static function googleAccountMismatch(): self
    {
        return new self(
            message: 'This email is linked to another Google account.',
            statusCode: 409,
        );
    }

    public static function persistenceFailed(?\Throwable $previous = null): self
    {
        return new self(
            message: 'An error occurred while creating or linking your account. Please try again.',
            statusCode: 500,
            previous: $previous,
        );
    }
}
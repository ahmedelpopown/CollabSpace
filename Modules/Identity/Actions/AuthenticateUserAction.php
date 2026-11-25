<?php

namespace Modules\Identity\Actions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\DTOs\AuthenticatedSessionData;
use Modules\Identity\Models\User;

class AuthenticateUserAction
{
    public function __construct(
        private readonly IssueAccessTokenAction $issueAccessToken,
        private readonly RecordAuthenticationLogAction $recordAuthenticationLog,
    ) {}

    public function execute(
        string $email,
        string $password,
        bool $rememberMe,
        ?string $deviceName,
        ?string $ipAddress,
        ?string $userAgent,
    ): AuthenticatedSessionData {
        $normalizedEmail = mb_strtolower(trim($email));

        $user = User::withTrashed()
            ->where('email', $normalizedEmail)
            ->first();

        if (
            ! $user ||
            ! $user->password ||
            ! Hash::check($password, $user->password)
        ) {
            $this->recordAuthenticationLog->execute(
                user: $user,
                attemptedEmail: $normalizedEmail,
                loginMethod: 'password',
                wasSuccessful: false,
                failureReason: 'invalid_credentials',
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            throw new AuthenticationException(
                'The provided credentials are incorrect.'
            );
        }

        if (! $user->is_active || $user->trashed()) {
            $this->recordAuthenticationLog->execute(
                user: $user,
                attemptedEmail: $normalizedEmail,
                loginMethod: 'password',
                wasSuccessful: false,
                failureReason: 'inactive_account',
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            throw new AuthenticationException(
                'The provided credentials are incorrect.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $normalizedEmail,
            $rememberMe,
            $deviceName,
            $ipAddress,
            $userAgent,
        ): AuthenticatedSessionData {
            $user->forceFill([
                'last_seen_at' => now(),
            ])->save();

            $session = $this->issueAccessToken->execute(
                $user,
                $rememberMe,
                $deviceName,
            );

            $this->recordAuthenticationLog->execute(
                user: $user,
                attemptedEmail: $normalizedEmail,
                loginMethod: 'password',
                wasSuccessful: true,
                failureReason: null,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $session;
        });
    }
}
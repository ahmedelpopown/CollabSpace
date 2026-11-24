<?php

namespace Modules\Identity\Services\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Modules\Identity\Enums\PasswordResetResult;
use Modules\Identity\Models\User;

final class PasswordResetService
{
    public function __construct(
        private readonly AccessTokenService $accessTokens,
    ) {}

    public function sendResetLink(string $email): void
    {
        Password::broker('users')->sendResetLink([
            'email' => mb_strtolower(trim($email)),
        ]);
    }

    public function resetPassword(
        string $email,
        string $token,
        string $password,
        string $passwordConfirmation,
    ): PasswordResetResult {
        $status = DB::transaction(function () use (
            $email,
            $token,
            $password,
            $passwordConfirmation,
        ): string {
            return Password::broker('users')->reset(
                [
                    'email' => mb_strtolower(trim($email)),
                    'token' => $token,
                    'password' => $password,
                    'password_confirmation' => $passwordConfirmation,
                ],
                function (User $user, string $password): void {
                    $user->forceFill(['password' => $password])->save();

                    // Password reset invalidates every previously issued API credential.
                    $this->accessTokens->revokeAll($user);

                    event(new PasswordReset($user));
                },
            );
        });

        return match ($status) {
            PasswordBroker::PASSWORD_RESET => PasswordResetResult::Success,
            PasswordBroker::RESET_THROTTLED => PasswordResetResult::Throttled,
            default => PasswordResetResult::Invalid,
        };
    }
}
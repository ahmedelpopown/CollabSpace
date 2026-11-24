<?php

namespace Modules\Identity\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\Identity\Actions\IssueAccessTokenAction;
use Modules\Identity\Actions\RecordAuthenticationLogAction;
use Modules\Identity\Exceptions\GoogleAuthException;
use Modules\Identity\Models\User;


use Throwable;

final class GoogleAuthService
{
    private const DEFAULT_ROLE = 'Team Member';

    public function __construct(
        private readonly IssueAccessTokenAction $issueAccessToken,
        private readonly RecordAuthenticationLogAction $recordAuthenticationLog,
    ) {}



public function getAuthorizationUrl(): string
{
    /** @var AbstractProvider $driver */
    $driver = Socialite::driver('google');

    return $driver
        ->stateless()
        ->scopes(['openid', 'email', 'profile'])
        ->redirect()
        ->getTargetUrl();
}

public function authenticate(
    string $deviceName,
    ?string $ipAddress,
    ?string $userAgent,
    ?string $accessToken = null,
): \Modules\Identity\DTOs\AuthenticatedSessionData {
    $googleUser = $this->fetchGoogleUser($accessToken);
    $profile = $this->extractVerifiedProfile($googleUser);
    $user = $this->resolveUser($profile);

    return DB::transaction(function () use (
        $user,
        $profile,
        $deviceName,
        $ipAddress,
        $userAgent,
    ): \Modules\Identity\DTOs\AuthenticatedSessionData {
        $user->forceFill(['last_seen_at' => now()])->save();

        $session = $this->issueAccessToken->execute(
            user: $user,
            rememberMe: false,
            deviceName: $deviceName,
        );

        $this->recordAuthenticationLog->execute(
            user: $user,
            attemptedEmail: $profile['email'],
            loginMethod: 'google',
            wasSuccessful: true,
            failureReason: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        return $session;
    });
}


private function fetchGoogleUser(?string $accessToken): SocialiteUser
{
    try {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('google');
        $provider = $driver->stateless();

        return filled($accessToken)
            ? $provider->userFromToken((string) $accessToken)
            : $provider->user();
    } catch (Throwable $exception) {
        report($exception);
        throw GoogleAuthException::providerFailed($exception);
    }
}

    /**
     * @return array{
     *     google_id: string,
     *     email: string,
     *     full_name: string,
     *     avatar_path: ?string
     * }
     */
    private function extractVerifiedProfile(SocialiteUser $googleUser): array
    {
        $googleId = trim((string) $googleUser->getId());
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $emailVerified = filter_var(
            data_get(
                $googleUser->user,
                'email_verified',
                data_get($googleUser->user, 'verified_email', false),
            ),
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($googleId === '' || $email === '' || ! $emailVerified) {
            throw GoogleAuthException::unverifiedAccount();
        }

        return [
            'google_id' => $googleId,
            'email' => $email,
            'full_name' => trim((string) $googleUser->getName()) ?: Str::before($email, '@'),
            'avatar_path' => $googleUser->getAvatar(),
        ];
    }

    /**
     * @param array{google_id: string, email: string, full_name: string, avatar_path: ?string} $profile
     */
    private function resolveUser(array $profile): User
    {
        try {
            return DB::transaction(function () use ($profile): User {
                $user = User::withTrashed()
                    ->where(function ($query) use ($profile): void {
                        $query->where('google_id', $profile['google_id'])
                            ->orWhere('email', $profile['email']);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($user !== null) {
                    return $this->linkExistingUser($user, $profile);
                }

                return $this->createUser($profile);
            });
        } catch (GoogleAuthException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw GoogleAuthException::persistenceFailed($exception);
        }
    }

    /**
     * @param array{google_id: string, email: string, full_name: string, avatar_path: ?string} $profile
     */
    private function linkExistingUser(User $user, array $profile): User
    {
        if (! $user->is_active || $user->trashed()) {
            throw GoogleAuthException::inactiveAccount();
        }

        if (
            filled($user->google_id)
            && ! hash_equals((string) $user->google_id, $profile['google_id'])
        ) {
            throw GoogleAuthException::googleAccountMismatch();
        }

        $user->forceFill([
            'google_id' => $profile['google_id'],
            'email_verified_at' => $user->email_verified_at ?? now(),
            'avatar_path' => $user->avatar_path ?: $profile['avatar_path'],
        ])->save();

        $this->ensureDefaultRole($user);

        return $user;
    }

    /**
     * @param array{google_id: string, email: string, full_name: string, avatar_path: ?string} $profile
     */
    private function createUser(array $profile): User
    {
        $user = new User();
        $user->forceFill([
            'full_name' => $profile['full_name'],
            'email' => $profile['email'],
            'password' => Str::random(64),
            'google_id' => $profile['google_id'],
            'email_verified_at' => now(),
            'avatar_path' => $profile['avatar_path'],
            'is_active' => true,
        ])->save();

        $this->ensureDefaultRole($user);

        return $user;
    }

    private function ensureDefaultRole(User $user): void
    {
        if (! $user->roles()->exists()) {
            $user->assignRole(self::DEFAULT_ROLE);
        }
    }
}
<?php

namespace Modules\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Identity\DTOs\AuthenticatedSessionData;
use Modules\Identity\Models\User;
use Spatie\Permission\Models\Role;

class RegisterUserAction
{
    public function __construct(
        private readonly IssueAccessTokenAction $issueAccessToken,
        private readonly RecordAuthenticationLogAction $recordAuthenticationLog,
    ) {}

    /**
     * @param array{
     *     full_name: string,
     *     email: string,
     *     password: string,
     *     remember_me?: bool,
     *     device_name?: string|null
     * } $data
     */
    public function execute(
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
    ): AuthenticatedSessionData {
        return DB::transaction(function () use (
            $data,
            $ipAddress,
            $userAgent,
        ): AuthenticatedSessionData {
            $user = User::query()->create([
                'full_name' => trim($data['full_name']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
                'is_active' => true,
            ]);

            // Public registration can only create a Team Member account.
            $teamMemberRole = Role::findByName('Team Member', 'web');

            $user->assignRole($teamMemberRole);

            $user->forceFill([
                'last_seen_at' => now(),
            ])->save();

            $session = $this->issueAccessToken->execute(
                $user,
                (bool) ($data['remember_me'] ?? false),
                $data['device_name'] ?? null,
            );

            $this->recordAuthenticationLog->execute(
                user: $user,
                attemptedEmail: $user->email,
                loginMethod: 'registration',
                wasSuccessful: true,
                failureReason: null,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $session;
        });
    }
}
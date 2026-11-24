<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Modules\Identity\Models\AuthenticationLog;
use Modules\Identity\Models\User;
use Modules\Identity\Notifications\PasswordResetNotification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('registers a new account as a team member and issues a sanctum token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'full_name' => 'Test Member',
        'email' => 'member@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'remember_me' => false,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'member@example.com')
        ->assertJsonPath('data.user.roles.0', 'Team Member')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['access_token', 'expires_at']]);

    $user = User::query()->where('email', 'member@example.com')->firstOrFail();

    expect($user->hasRole('Team Member'))->toBeTrue();
    expect($user->hasRole('Administrator'))->toBeFalse();
    expect($user->tokens()->count())->toBe(1);

    $this->assertDatabaseHas('authentication_logs', [
        'user_id' => $user->id,
        'login_method' => 'registration',
        'was_successful' => true,
    ]);
});

it('authenticates a user with valid credentials and returns their roles and permissions', function (): void {
    $user = User::factory()->create([
        'email' => 'member@example.com',
        'password' => 'StrongPass123!',
    ]);
    $user->assignRole('Team Member');

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'MEMBER@example.com',
        'password' => 'StrongPass123!',
        'remember_me' => false,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.roles.0', 'Team Member')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['access_token', 'expires_at', 'user' => ['permissions']]]);

    $this->assertDatabaseHas('authentication_logs', [
        'user_id' => $user->id,
        'login_method' => 'password',
        'was_successful' => true,
    ]);
});

it('rejects invalid credentials without revealing whether the email exists', function (): void {
    $user = User::factory()->create(['email' => 'member@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'member@example.com',
        'password' => 'wrong-password',
    ])->assertUnauthorized();

    $this->assertDatabaseHas('authentication_logs', [
        'user_id' => $user->id,
        'was_successful' => false,
        'failure_reason' => 'invalid_credentials',
    ]);
});

it('does not allow inactive users to log in', function (): void {
    User::factory()->create([
        'email' => 'inactive@example.com',
        'password' => 'StrongPass123!',
        'is_active' => false,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'StrongPass123!',
    ])->assertForbidden();
});

it('returns the authenticated user when a valid bearer token is provided', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Team Member');
    $plainTextToken = $user->createToken('test-device')->plainTextToken;

    $this->withToken($plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);
});

it('revokes tokens when an account is deactivated after login', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Team Member');
    $plainTextToken = $user->createToken('test-device')->plainTextToken;

    $user->forceFill(['is_active' => false])->save();

    $this->withToken($plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertForbidden();

    expect($user->tokens()->count())->toBe(0);
});

it('revokes only the current token on logout', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Team Member');

    $token = $user->createToken('test-device');

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('sends a password reset notification without disclosing unknown emails', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'member@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $user->email,
    ])->assertOk();

    Notification::assertSentTo($user, PasswordResetNotification::class);

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'unknown@example.com',
    ])->assertOk();
});

it('resets the password and revokes existing api tokens', function (): void {
    $user = User::factory()->create(['email' => 'member@example.com']);
    $user->createToken('old-device');
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => 'NewStrongPass123!',
        'password_confirmation' => 'NewStrongPass123!',
    ])->assertOk();

    expect(Hash::check('NewStrongPass123!', $user->fresh()->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
});


it('rate limits repeated login attempts by both client IP and normalized email', function (): void {
    User::factory()->create(['email' => 'limited@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'LIMITED@example.com',
            'password' => 'WrongPassword123!',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'limited@example.com',
        'password' => 'WrongPassword123!',
    ])->assertTooManyRequests();
});

it('revokes all personal access tokens when a user is soft deleted', function (): void {
    $user = User::factory()->create();
    $user->createToken('first-device');
    $user->createToken('second-device');

    $user->delete();

    expect($user->tokens()->count())->toBe(0);
});

it('rejects weak passwords during registration', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'full_name' => 'Test Member',
        'email' => 'weak-password@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertUnprocessable();
});

<?php

namespace Modules\Identity\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Actions\AuthenticateUserAction;
use Modules\Identity\Actions\LogoutAllUserSessionsAction;
use Modules\Identity\Actions\LogoutUserAction;
use Modules\Identity\Actions\RegisterUserAction;
use Modules\Identity\DTOs\AuthenticatedSessionData;
use Modules\Identity\Http\Resources\AuthenticatedUserResource;
use Modules\Identity\Requests\Api\V1\Auth\LoginRequest;
use Modules\Identity\Requests\Api\V1\Auth\RegisterRequest;

class AuthController extends Controller
{
    public function register(
        RegisterRequest $request,
        RegisterUserAction $action,
    ): JsonResponse {
        $session = $action->execute(
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
        );

        return $this->noStore(response()->json([
            'message' => 'Account created successfully.',
            'data' => $this->authenticatedPayload($session, $request),
        ], JsonResponse::HTTP_CREATED));
    }

    public function login(
        LoginRequest $request,
        AuthenticateUserAction $action,
    ): JsonResponse {
        $session = $action->execute(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            rememberMe: $request->boolean('remember_me'),
            deviceName: $request->input('device_name'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return $this->noStore(response()->json([
            'message' => 'Login successful.',
            'data' => $this->authenticatedPayload($session, $request),
        ]));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => AuthenticatedUserResource::make($request->user())->resolve($request),
            ],
        ]);
    }

    public function logout(
        Request $request,
        LogoutUserAction $action,
    ): JsonResponse {
        $action->execute($request->user());

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function logoutAll(
        Request $request,
        LogoutAllUserSessionsAction $action,
    ): JsonResponse {
        $action->execute($request->user());

        return response()->json([
            'message' => 'All sessions have been revoked.',
        ]);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    /**
     * @return array{
     *     user: array<string, mixed>,
     *     access_token: string,
     *     token_type: string,
     *     expires_at: string
     * }
     */
    private function authenticatedPayload(
        AuthenticatedSessionData $session,
        Request $request,
    ): array {
        return [
            'user' => AuthenticatedUserResource::make($session->user)->resolve($request),
            'access_token' => $session->accessToken,
            'token_type' => $session->tokenType,
            'expires_at' => $session->expiresAt,
        ];
    }
}
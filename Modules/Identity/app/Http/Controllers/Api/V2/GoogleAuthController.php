<?php

namespace Modules\Identity\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\GoogleAuthException;
use Modules\Identity\Http\Resources\AuthenticatedUserResource;
use Modules\Identity\Services\Auth\GoogleAuthService;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleAuthService $googleAuthService,
    ) {}

    public function redirect(): JsonResponse
    {
        return $this->noStore(response()->json([
            'auth_url' => $this->googleAuthService->getAuthorizationUrl(),
        ]));
    }

    public function callback(Request $request): JsonResponse
    {
        try {
            $deviceName = trim((string) ($request->userAgent() ?: 'google-oauth-client'));
            $session = $this->googleAuthService->authenticate(
                deviceName: mb_substr($deviceName, 0, 100),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );

            return $this->noStore(response()->json([
                'message' => 'Google authentication successful.',
                'data' => [
                    'user' => AuthenticatedUserResource::make($session->user)->resolve($request),
                    'access_token' => $session->accessToken,
                    'token_type' => $session->tokenType,
                    'expires_at' => $session->expiresAt,
                ],
            ]));
        } catch (GoogleAuthException $exception) {
            return $this->noStore(response()->json([
                'message' => $exception->getMessage(),
            ], $exception->statusCode));
        }
    }
}

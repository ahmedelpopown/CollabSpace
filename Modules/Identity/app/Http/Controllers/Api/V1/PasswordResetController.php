<?php

namespace Modules\Identity\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Requests\Api\V1\Auth\ForgotPasswordRequest;
use Modules\Identity\Requests\Api\V1\Auth\ResetPasswordRequest;
use Modules\Identity\Services\Auth\PasswordResetService;

class PasswordResetController extends Controller
{
    public function sendResetLink(
        ForgotPasswordRequest $request,
        PasswordResetService $service,
    ): JsonResponse {
        $service->sendResetLink(
            $request->string('email')->toString()
        );

        return response()->json([
            'message' => 'If an account with that email exists, a password reset link will be sent.',
        ]);
    }

    public function reset(
        ResetPasswordRequest $request,
        PasswordResetService $service,
    ): JsonResponse {
        $result = $service->resetPassword(
            email: $request->string('email')->toString(),
            token: $request->string('token')->toString(),
            password: $request->string('password')->toString(),
            passwordConfirmation: $request->string('password_confirmation')->toString(),
        );

        if (! $result->successful()) {
            return response()->json([
                'message' => 'The password reset token is invalid, expired, or has been throttled.',
                'errors' => [
                    'token' => [
                        'Please request a new password reset link.',
                    ],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }
}
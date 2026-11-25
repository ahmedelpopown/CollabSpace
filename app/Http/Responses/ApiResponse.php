<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ApiResponse
{
    public static function success(
        string $message,
        mixed $data = null,
        int $status = JsonResponse::HTTP_OK,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function paginated(
        AnonymousResourceCollection $resourceCollection,
        string $message,
        int $status = JsonResponse::HTTP_OK,
    ): JsonResponse {
        $resourcePayload = $resourceCollection->response()->getData(true);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resourcePayload['data'] ?? [],
            'meta' => $resourcePayload['meta'] ?? [],
            'links' => $resourcePayload['links'] ?? [],
        ], $status);
    }

    public static function failure(
        string $message,
        int $status,
        ?array $errors = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'data' => null,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}

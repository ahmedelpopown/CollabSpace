<?php

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active || $user->trashed()) {
            if ($user && method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            return response()->json([
                'message' => 'This account is inactive. Please contact an administrator.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

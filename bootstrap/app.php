<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Modules\Identity\Http\Middleware\EnsureUserIsActive;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);
        // API-only application: unauthenticated requests must return 401 JSON,
        // never redirect to a web login route that does not exist.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            

            if ($exception instanceof ValidationException) {
                return ApiResponse::failure(
                    'The given data was invalid.',
                    422,
                    $exception->errors(),
                );
            }

            if ($exception instanceof AuthenticationException) {
                return ApiResponse::failure('Unauthenticated.', 401);
            }

            if ($exception instanceof AuthorizationException) {
                return ApiResponse::failure('This action is unauthorized.', 403);
            }

            if ($exception instanceof ModelNotFoundException) {
                return ApiResponse::failure('The requested resource was not found.', 404);
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $message = match ($status) {
                    404 => 'The requested resource was not found.',
                    405 => 'The requested method is not allowed.',
                    429 => 'Too many requests. Please try again later.',
                    default => $status >= 500
                        ? 'An unexpected server error occurred.'
                        : 'The request could not be processed.',
                };

                return ApiResponse::failure($message, $status);
            }

            return ApiResponse::failure('An unexpected server error occurred.', 500);
        });
    })
    ->create();

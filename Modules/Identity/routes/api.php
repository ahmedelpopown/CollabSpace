<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\Api\V1\AuthController;
use Modules\Identity\Http\Controllers\Api\V1\PasswordResetController;
use Modules\Identity\Http\Controllers\Api\V2\GoogleAuthController;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::prefix('auth')
            ->name('auth.')
            ->group(function (): void {
                Route::post('/register', [AuthController::class, 'register'])
                    ->middleware('throttle:auth-register')
                    ->name('register');

                Route::post('/login', [AuthController::class, 'login'])
                    ->middleware('throttle:auth-login')
                    ->name('login');

                Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
                    ->middleware('throttle:3,1')
                    ->name('password.email');

                Route::post('/reset-password', [PasswordResetController::class, 'reset'])
                    ->middleware('throttle:5,1')
                    ->name('password.update');

                Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
                    Route::get('/me', [AuthController::class, 'me'])->name('me');

                    Route::post('/logout', [AuthController::class, 'logout'])
                        ->name('logout');

                    Route::post('/logout-all', [AuthController::class, 'logoutAll'])
                        ->name('logout-all');
                });
            });
    });

Route::prefix('v2/auth/google')
    ->name('api.v2.auth.google.')
    ->middleware(['web', 'throttle:auth-google'])
    ->group(function (): void {
        Route::get('/redirect', [GoogleAuthController::class, 'redirect'])
            ->name('redirect');

        Route::get('/callback', [GoogleAuthController::class, 'callback'])
            ->name('callback');
    });

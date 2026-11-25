<?php

use Illuminate\Support\Facades\Route;
use Modules\Files\Http\Controllers\Api\V1\DownloadFileController;
use Modules\Files\Http\Controllers\Api\V1\ProjectFileController;
use Modules\Files\Http\Controllers\Api\V1\UploadProjectFileController;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'active'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('projects/{project}/files', [ProjectFileController::class, 'index'])
            ->name('projects.files.index');

        Route::post('projects/{project}/files', UploadProjectFileController::class)
            ->middleware('throttle:10,1')
            ->name('projects.files.store');

        Route::get('files/{file}/download', DownloadFileController::class)
            ->name('files.download');
    });

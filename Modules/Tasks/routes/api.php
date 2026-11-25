<?php

use Illuminate\Support\Facades\Route;
use Modules\Tasks\Http\Controllers\Api\V1\CreateTaskController;
use Modules\Tasks\Http\Controllers\Api\V1\DeleteTaskController;
use Modules\Tasks\Http\Controllers\Api\V1\ShowTaskController;
use Modules\Tasks\Http\Controllers\Api\V1\TaskController;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'active'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('projects/{project}/tasks', [TaskController::class, 'index'])
            ->name('projects.tasks.index');

        Route::post('projects/{project}/tasks', CreateTaskController::class)
            ->name('projects.tasks.store');

        Route::get('projects/{project}/tasks/{task}', ShowTaskController::class)
            ->name('projects.tasks.show');

        Route::patch('projects/{project}/tasks/{task}', [TaskController::class, 'update'])
            ->name('projects.tasks.update');

        Route::delete('projects/{project}/tasks/{task}', DeleteTaskController::class)
            ->name('projects.tasks.destroy');
    });

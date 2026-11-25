<?php

use Illuminate\Support\Facades\Route;
use Modules\Projects\Http\Controllers\Api\V1\AssignProjectTeamController;
use Modules\Projects\Http\Controllers\Api\V1\CreateProjectController;
use Modules\Projects\Http\Controllers\Api\V1\DetachProjectTeamController;
use Modules\Projects\Http\Controllers\Api\V1\ProjectController;
use Modules\Projects\Http\Controllers\Api\V1\ShowProjectController;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'active'])
    ->group(function (): void {
        Route::get('projects', [ProjectController::class, 'index'])
            ->name('projects.index');

        Route::post('projects', CreateProjectController::class)
            ->name('projects.store');

        Route::get('projects/{project}', ShowProjectController::class)
            ->name('projects.show');

        Route::match(['put', 'patch'], 'projects/{project}', [ProjectController::class, 'update'])
            ->name('projects.update');

        Route::post('projects/{project}/teams', AssignProjectTeamController::class)
            ->name('projects.teams.store');

        Route::delete('projects/{project}/teams/{team}', DetachProjectTeamController::class)
            ->scopeBindings()
            ->name('projects.teams.destroy');
    });

<?php

use Illuminate\Support\Facades\Route;
use Modules\Teams\Http\Controllers\Api\V1\AddTeamMemberController;
use Modules\Teams\Http\Controllers\Api\V1\CreateTeamController;
use Modules\Teams\Http\Controllers\Api\V1\DeleteTeamController;
use Modules\Teams\Http\Controllers\Api\V1\RemoveTeamMemberController;
use Modules\Teams\Http\Controllers\Api\V1\ShowTeamController;
use Modules\Teams\Http\Controllers\Api\V1\TeamController;
use Modules\Teams\Http\Controllers\Api\V1\TeamManagementController;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'active'])
    ->scopeBindings()
    ->group(function (): void {
        Route::get('teams', [TeamManagementController::class, 'index'])
            ->name('teams.index');

        Route::post('teams', CreateTeamController::class)
            ->name('teams.store');

        Route::get('teams/{team}', ShowTeamController::class)
            ->name('teams.show');

        Route::match(['put', 'patch'], 'teams/{team}', [TeamManagementController::class, 'update'])
            ->name('teams.update');

        Route::delete('teams/{team}', DeleteTeamController::class)
            ->name('teams.destroy');

        Route::get('projects/{project}/teams', [TeamController::class, 'index'])
            ->name('projects.teams.index');

        Route::post('teams/{team}/members', AddTeamMemberController::class)
            ->name('teams.members.store');

        Route::delete('teams/{team}/members/{member}', RemoveTeamMemberController::class)
            ->name('teams.members.destroy');
    });

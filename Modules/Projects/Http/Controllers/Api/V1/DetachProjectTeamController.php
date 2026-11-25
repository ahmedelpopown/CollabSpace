<?php

namespace Modules\Projects\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Actions\DetachProjectTeamAction;
use Modules\Projects\Models\Project;
use Modules\Teams\Models\Team;

class DetachProjectTeamController extends Controller
{
    public function __invoke(
        Request $request,
        Project $project,
        Team $team,
        DetachProjectTeamAction $action,
    ): JsonResponse {
        $action->execute($project, $team, $request->user());

        return ApiResponse::success(
            'Team removed from project successfully.',
            [
                'project_id' => $project->getKey(),
                'team_id' => $team->getKey(),
            ],
        );
    }
}

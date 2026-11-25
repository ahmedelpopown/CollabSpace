<?php

namespace Modules\Projects\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Actions\AssignProjectTeamAction;
use Modules\Projects\Models\Project;
use Modules\Projects\Requests\Api\V1\Projects\AssignProjectTeamRequest;

class AssignProjectTeamController extends Controller
{
    public function __invoke(
        AssignProjectTeamRequest $request,
        Project $project,
        AssignProjectTeamAction $action,
    ): JsonResponse {
        $result = $action->executeById(
            $project,
            (int) $request->validated('team_id'),
            $request->user(),
        );

        return ApiResponse::success(
            $result->assigned
                ? 'Team assigned to project successfully.'
                : 'Team is already assigned to this project.',
            [
                'project_id' => $project->getKey(),
                'team' => [
                    'id' => $result->team->getKey(),
                    'name' => $result->team->name,
                ],
                'assigned_by_user_id' => $request->user()->getKey(),
                'assigned' => $result->assigned,
            ],
            $result->assigned ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
        );
    }
}

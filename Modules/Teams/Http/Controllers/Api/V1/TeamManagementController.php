<?php

namespace Modules\Teams\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Teams\Actions\UpdateTeamAction;
use Modules\Teams\Http\Resources\Api\V1\TeamManagementResource;
use Modules\Teams\Models\Team;
use Modules\Teams\Requests\Api\V1\TeamManagementIndexRequest;
use Modules\Teams\Requests\Api\V1\UpdateTeamRequest;
use Modules\Teams\Services\TeamManagementQueryService;

class TeamManagementController extends Controller
{
    public function index(
        TeamManagementIndexRequest $request,
        TeamManagementQueryService $queries,
    ): JsonResponse {
        $teams = $queries->paginateForOrganization($request->user(), $request->validated());

        return ApiResponse::paginated(
            TeamManagementResource::collection($teams),
            'Teams retrieved successfully.',
        );
    }

    public function update(
        UpdateTeamRequest $request,
        Team $team,
        UpdateTeamAction $action,
        TeamManagementQueryService $queries,
    ): JsonResponse {
        $team = $action->execute($team, $request->user(), $request->validated());
        $team = $queries->loadDetails($team, $request->user());

        return ApiResponse::success(
            'Team updated successfully.',
            TeamManagementResource::make($team)->resolve($request),
        );
    }
}

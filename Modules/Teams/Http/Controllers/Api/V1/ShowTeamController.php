<?php

namespace Modules\Teams\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Teams\Http\Resources\Api\V1\TeamManagementResource;
use Modules\Teams\Models\Team;
use Modules\Teams\Requests\Api\V1\ShowTeamRequest;
use Modules\Teams\Services\TeamManagementQueryService;

class ShowTeamController extends Controller
{
    public function __invoke(
        ShowTeamRequest $request,
        Team $team,
        TeamManagementQueryService $queries,
    ): JsonResponse {
        $team = $queries->loadDetails($team, $request->user());

        return ApiResponse::success(
            'Team retrieved successfully.',
            TeamManagementResource::make($team)->resolve($request),
        );
    }
}

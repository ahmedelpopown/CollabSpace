<?php

namespace Modules\Teams\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Models\Project;
use Modules\Teams\Http\Resources\Api\V1\TeamResource;
use Modules\Teams\Requests\Api\V1\TeamIndexRequest;
use Modules\Teams\Services\TeamQueryService;

class TeamController extends Controller
{
    public function index(
        TeamIndexRequest $request,
        Project $project,
        TeamQueryService $queries,
    ): JsonResponse {
        $teams = $queries->paginateForProject($project, $request->validated());

        return ApiResponse::paginated(
            TeamResource::collection($teams),
            'Project teams retrieved successfully.',
        );
    }
}

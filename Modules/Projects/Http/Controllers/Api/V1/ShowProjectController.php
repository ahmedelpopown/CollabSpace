<?php

namespace Modules\Projects\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Http\Resources\ProjectResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\ProjectQueryService;

class ShowProjectController extends Controller
{
    public function __invoke(
        Request $request,
        Project $project,
        ProjectQueryService $queries,
    ): JsonResponse {
      
        $project = $queries->loadDetails($project);

        return ApiResponse::success(
            'Project retrieved successfully.',
            ProjectResource::make($project)->resolve($request),
        );
    }
}

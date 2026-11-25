<?php

namespace Modules\Projects\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Actions\UpdateProjectAction;
use Modules\Projects\Http\Resources\ProjectResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Requests\Api\V1\Projects\ProjectIndexRequest;
use Modules\Projects\Requests\Api\V1\Projects\UpdateProjectRequest;
use Modules\Projects\Services\ProjectQueryService;

class ProjectController extends Controller
{
    public function index(
        ProjectIndexRequest $request,
        ProjectQueryService $queries,
    ): JsonResponse {
        $projects = $queries->paginateVisibleTo($request->user(), $request->validated());

        return ApiResponse::paginated(
            ProjectResource::collection($projects),
            'Projects retrieved successfully.',
        );
    }

    public function update(
        UpdateProjectRequest $request,
        Project $project,
        UpdateProjectAction $action,
        ProjectQueryService $queries,
    ): JsonResponse {
        $project = $action->execute($project, $request->user(), $request->validated());
        $project = $queries->loadDetails($project);

        return ApiResponse::success(
            'Project updated successfully.',
            ProjectResource::make($project)->resolve($request),
        );
    }
}

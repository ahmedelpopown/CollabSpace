<?php

namespace Modules\Projects\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Actions\CreateProjectAction;
use Modules\Projects\Http\Resources\ProjectResource;
use Modules\Projects\Requests\Api\V1\Projects\StoreProjectRequest;
use Modules\Projects\Services\ProjectQueryService;

class CreateProjectController extends Controller
{
    public function __invoke(
        StoreProjectRequest $request,
        CreateProjectAction $action,
        ProjectQueryService $queries,
    ): JsonResponse {
        $project = $action->execute($request->user(), $request->validated());
        $project = $queries->loadDetails($project);

        return ApiResponse::success(
            'Project created successfully.',
            ProjectResource::make($project)->resolve($request),
            JsonResponse::HTTP_CREATED,
        );
    }
}

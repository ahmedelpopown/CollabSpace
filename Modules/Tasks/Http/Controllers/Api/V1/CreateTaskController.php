<?php

namespace Modules\Tasks\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Models\Project;
use Modules\Tasks\Actions\CreateTaskAction;
use Modules\Tasks\Http\Resources\Api\V1\TaskResource;
use Modules\Tasks\Requests\Api\V1\StoreTaskRequest;
use Modules\Tasks\Services\TaskQueryService;

class CreateTaskController extends Controller
{
    public function __invoke(
        StoreTaskRequest $request,
        Project $project,
        CreateTaskAction $action,
        TaskQueryService $queries,
    ): JsonResponse {
        $task = $action->execute($project, $request->user(), $request->validated());
        $task = $queries->loadDetails($project, $task);

        return ApiResponse::success(
            'Task created successfully.',
            TaskResource::make($task)->resolve($request),
            JsonResponse::HTTP_CREATED,
        );
    }
}

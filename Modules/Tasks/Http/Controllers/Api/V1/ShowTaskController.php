<?php

namespace Modules\Tasks\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Models\Project;
use Modules\Tasks\Http\Resources\Api\V1\TaskResource;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Requests\Api\V1\ShowTaskRequest;
use Modules\Tasks\Services\TaskQueryService;

class ShowTaskController extends Controller
{
    public function __invoke(ShowTaskRequest $request, Project $project, Task $task, TaskQueryService $queries): JsonResponse
    {
        $task = $queries->loadDetails($project, $task);

        return ApiResponse::success('Task retrieved successfully.', TaskResource::make($task));
    }
}

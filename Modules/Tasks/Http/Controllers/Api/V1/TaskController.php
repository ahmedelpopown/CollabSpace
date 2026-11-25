<?php

namespace Modules\Tasks\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Models\Project;
use Modules\Tasks\Actions\UpdateTaskForUserAction;
use Modules\Tasks\Http\Resources\Api\V1\TaskResource;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Requests\Api\V1\TaskIndexRequest;
use Modules\Tasks\Requests\Api\V1\UpdateTaskRequest;
use Modules\Tasks\Services\TaskQueryService;

class TaskController extends Controller
{
    public function index(
        TaskIndexRequest $request,
        Project $project,
        TaskQueryService $queries,
    ): JsonResponse {
        $board = $queries->board($project, $request->user(), $request->validated());

        return ApiResponse::success('Project tasks retrieved successfully.', $board);
    }

    public function update(
        UpdateTaskRequest $request,
        Project $project,
        Task $task,
        UpdateTaskForUserAction $updateTask,
        TaskQueryService $queries,
    ): JsonResponse {
        $updatedTask = $updateTask->execute($task, $request->user(), $request->validated());
        $updatedTask = $queries->loadDetails($project, $updatedTask);

        return ApiResponse::success(
            'Task updated successfully.',
            TaskResource::make($updatedTask)->resolve($request),
        );
    }
}

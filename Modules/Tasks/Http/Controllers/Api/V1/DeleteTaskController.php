<?php

namespace Modules\Tasks\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tasks\Actions\DeleteTaskAction;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

class DeleteTaskController extends Controller
{
    public function __invoke(Request $request, Project $project, Task $task, DeleteTaskAction $action): JsonResponse
    {
        // Keep the parent Project argument so Laravel can resolve the nested Task through scoped bindings.
        $action->execute($task, $request->user());

        return ApiResponse::success('Task deleted successfully.');
    }
}

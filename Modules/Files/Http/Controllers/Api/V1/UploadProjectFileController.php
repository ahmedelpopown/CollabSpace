<?php

namespace Modules\Files\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Files\Actions\UploadProjectFileAction;
use Modules\Files\Http\Resources\Api\V1\FileResource;
use Modules\Files\Requests\Api\V1\UploadProjectFileRequest;
use Modules\Projects\Models\Project;

class UploadProjectFileController extends Controller
{
    public function __invoke(
        UploadProjectFileRequest $request,
        Project $project,
        UploadProjectFileAction $action,
    ): JsonResponse {
        $file = $action->execute($project, $request->file('file'), $request->user());

        return ApiResponse::success(
            'File uploaded successfully.',
            FileResource::make($file)->resolve($request),
            JsonResponse::HTTP_CREATED,
        );
    }
}

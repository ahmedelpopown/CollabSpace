<?php

namespace Modules\Files\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Files\Http\Resources\Api\V1\FileResource;
use Modules\Files\Requests\Api\V1\ProjectFileIndexRequest;
use Modules\Files\Services\ProjectFileQueryService;
use Modules\Projects\Models\Project;

class ProjectFileController extends Controller
{
    public function index(
        ProjectFileIndexRequest $request,
        Project $project,
        ProjectFileQueryService $queries,
    ): JsonResponse {
        $result = $queries->getForProject($project, (int) $request->validated('per_page', 12));
        $paginator = $result['files'];

        return ApiResponse::success(
            'Project files retrieved successfully.',
            [
                'recent' => FileResource::collection($result['recent'])->resolve($request),
                'files' => FileResource::collection($paginator)->resolve($request),
            ],
            meta: [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'next_page_url' => $paginator->nextPageUrl(),
                    'prev_page_url' => $paginator->previousPageUrl(),
                ],
            ],
        );
    }
}

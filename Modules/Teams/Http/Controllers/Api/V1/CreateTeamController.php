<?php

namespace Modules\Teams\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Teams\Actions\CreateTeamAction;
use Modules\Teams\Http\Resources\Api\V1\TeamManagementResource;
use Modules\Teams\Requests\Api\V1\StoreTeamRequest;
use Modules\Teams\Services\TeamManagementQueryService;

class CreateTeamController extends Controller
{
    public function __invoke(
        StoreTeamRequest $request,
        CreateTeamAction $action,
        TeamManagementQueryService $queries,
    ): JsonResponse {
        $team = $action->execute($request->user(), $request->validated());
        $team = $queries->loadDetails($team, $request->user());

        return ApiResponse::success(
            'Team created successfully.',
            TeamManagementResource::make($team)->resolve($request),
            JsonResponse::HTTP_CREATED,
        );
    }
}

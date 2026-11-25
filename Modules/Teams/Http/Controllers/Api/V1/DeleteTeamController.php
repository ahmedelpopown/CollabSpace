<?php

namespace Modules\Teams\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Teams\Actions\DeleteTeamAction;
use Modules\Teams\Models\Team;

class DeleteTeamController extends Controller
{
    public function __invoke(Request $request, Team $team, DeleteTeamAction $action): JsonResponse
    {
        $action->execute($team, $request->user());

        return ApiResponse::success('Team deleted successfully.', [
            'id' => $team->getKey(),
            'deleted' => true,
        ]);
    }
}

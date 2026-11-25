<?php

namespace Modules\Teams\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Models\User;
use Modules\Teams\Actions\RemoveTeamMemberAction;
use Modules\Teams\Models\Team;

class RemoveTeamMemberController extends Controller
{
    public function __invoke(Team $team, User $member, RemoveTeamMemberAction $action): JsonResponse
    {
        $action->execute($team, $member, request()->user());

        return ApiResponse::success('Team member removed successfully.');
    }
}

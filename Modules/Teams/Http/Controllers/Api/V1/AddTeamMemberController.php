<?php

namespace Modules\Teams\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Teams\Actions\AddTeamMemberAction;
use Modules\Teams\Models\Team;
use Modules\Teams\Requests\Api\V1\AddTeamMemberRequest;

class AddTeamMemberController extends Controller
{
    public function __invoke(AddTeamMemberRequest $request, Team $team, AddTeamMemberAction $action): JsonResponse
    {
        $membership = $action->execute(
            $team,
            (int) $request->validated('user_id'),
            $request->user(),
            $request->validated('team_role', 'member'),
        );

        return ApiResponse::success('Team member added successfully.', [
            'team_id' => $membership->team_id,
            'user_id' => $membership->user_id,
            'team_role' => $membership->team_role,
            'membership_status' => $membership->membership_status,
        ], JsonResponse::HTTP_CREATED);
    }
}

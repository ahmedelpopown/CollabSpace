<?php

namespace Modules\Teams\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Identity\Models\User */
class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignedTasks = (int) ($this->assigned_tasks_count ?? 0);
        $completedTasks = (int) ($this->completed_tasks_count ?? 0);

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'job_title' => $this->job_title,
            'avatar_path' => $this->avatar_path,
            'team_role' => $this->pivot?->team_role,
            'tasks_count' => $assignedTasks,
            'completed_tasks_count' => $completedTasks,
            'progress_percentage' => round((float) ($this->assigned_tasks_progress_percentage ?? 0), 2),
        ];
    }
}

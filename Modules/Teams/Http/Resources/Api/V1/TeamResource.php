<?php

namespace Modules\Teams\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Teams\Models\Team */
class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tasksCount = (int) ($this->project_tasks_count ?? 0);
        $completedTasksCount = (int) ($this->completed_project_tasks_count ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'progress_percentage' => round((float) ($this->project_progress_percentage ?? 0), 2),
            'tasks_count' => $tasksCount,
            'completed_tasks_count' => $completedTasksCount,
            'members_count' => $this->whenLoaded('activeMembers', fn () => $this->activeMembers->count()),
            'members' => TeamMemberResource::collection($this->whenLoaded('activeMembers')),
        ];
    }
}

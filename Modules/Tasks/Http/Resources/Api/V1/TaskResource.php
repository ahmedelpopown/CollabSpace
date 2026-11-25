<?php

namespace Modules\Tasks\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Identity\Http\Resources\Api\V1\UserSummaryResource;

/** @mixin \Modules\Tasks\Models\Task */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'progress_percentage' => $this->progress_percentage,
            'due_at' => $this->due_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'assigned_team' => $this->whenLoaded('assignedTeam', fn () => $this->assignedTeam ? [
                'id' => $this->assignedTeam->id,
                'name' => $this->assignedTeam->name,
            ] : null),
            'assignees' => UserSummaryResource::collection($this->whenLoaded('assignees')),
            'assignees_count' => $this->whenCounted('assignees'),
            'files_count' => $this->whenCounted('files'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? UserSummaryResource::make($this->creator) : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

<?php

namespace Modules\Teams\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Teams\Models\Team */
class TeamManagementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'full_name' => $this->owner->full_name,
                'job_title' => $this->owner->job_title,
                'avatar_path' => $this->owner->avatar_path,
            ] : null),
            'members_count' => (int) ($this->members_count ?? 0),
            'projects_count' => (int) ($this->projects_count ?? 0),
            'tasks_count' => (int) ($this->tasks_count ?? 0),
            'members' => TeamMemberResource::collection($this->whenLoaded('activeMembers')),
            'projects' => $this->whenLoaded('projects', fn () => $this->projects->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

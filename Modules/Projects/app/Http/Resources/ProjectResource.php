<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Projects\Models\Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totalTasks = (int) ($this->tasks_count ?? 0);
        $completedTasks = (int) ($this->completed_tasks_count ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'starts_on' => $this->starts_on?->toDateString(),
            'deadline' => $this->deadline?->toDateString(),
            'is_delayed' => $this->isDelayed(),
            'completion_percentage' => $totalTasks > 0
                ? round(($completedTasks / $totalTasks) * 100, 2)
                : 0.0,
            'counts' => [
                'tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'in_progress_tasks' => (int) ($this->in_progress_tasks_count ?? 0),
                'in_review_tasks' => (int) ($this->in_review_tasks_count ?? 0),
                'pending_tasks' => (int) ($this->pending_tasks_count ?? 0),
                'files' => (int) ($this->files_count ?? 0),
                'teams' => (int) ($this->teams_count ?? 0),
                'members' => (int) ($this->active_members_count ?? 0),
            ],
            'creator' => $this->whenLoaded('creator', fn ($creator): ?array => $creator ? [
                'id' => $creator->id,
                'full_name' => $creator->full_name,
                'avatar_path' => $creator->avatar_path,
            ] : null),
            'teams' => $this->whenLoaded('teams', fn () => $this->teams
                ->map(fn ($team): array => [
                    'id' => $team->id,
                    'name' => $team->name,
                ])
                ->values()),
            'members' => $this->whenLoaded('activeMembers', fn () => $this->activeMembers
                ->map(fn ($member): array => [
                    'id' => $member->id,
                    'full_name' => $member->full_name,
                    'avatar_path' => $member->avatar_path,
                ])
                ->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

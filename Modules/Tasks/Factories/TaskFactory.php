<?php

namespace Modules\Tasks\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'created_by_user_id' => null,
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'status' => 'pending',
            'due_at' => now()->addDays(7),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Task $task) {
            $assignee = $task->project->activeMembers()->first();

            if (! $assignee) {
                $assignee = User::factory()->create();
                $task->project->members()->attach($assignee->getKey(), [
                    'membership_status' => 'active',
                    'added_by_user_id' => $assignee->getKey(),
                    'joined_at' => now(),
                ]);
            }

            $task->assignees()->syncWithoutDetaching([
                $assignee->getKey() => ['assigned_at' => now()],
            ]);
            $task->forceFill(['created_by_user_id' => $task->project->created_by_user_id])->save();
        });
    }
}

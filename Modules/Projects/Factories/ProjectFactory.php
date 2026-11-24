<?php

namespace Modules\Projects\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'created_by_user_id' => User::factory(),
            'starts_on' => today(),
            'deadline' => today()->addDays(30),
            'priority' => 'medium',
            'status' => 'in_progress',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project) {
            $project->members()->syncWithoutDetaching([
                $project->created_by_user_id => [
                    'membership_status' => 'active',
                    'added_by_user_id' => $project->created_by_user_id,
                    'joined_at' => now(),
                ],
            ]);
        });
    }
}

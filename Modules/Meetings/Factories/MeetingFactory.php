<?php

namespace Modules\Meetings\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Meetings\Models\Meeting;
use Modules\Projects\Models\Project;

/** @extends Factory<Meeting> */
class MeetingFactory extends Factory
{
    protected $model = Meeting::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
            'status' => 'scheduled',
        ];
    }
}

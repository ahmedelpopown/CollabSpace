<?php

namespace Modules\Meetings\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingSummary;

/** @extends Factory<MeetingSummary> */
class MeetingSummaryFactory extends Factory
{
    protected $model = MeetingSummary::class;

    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'summary_text' => fake()->paragraph(),
            'generated_at' => now(),
        ];
    }
}

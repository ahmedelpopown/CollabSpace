<?php

namespace Modules\Teams\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Identity\Models\User;
use Modules\Teams\Models\Team;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Team',
            'description' => fake()->sentence(),
            'owner_user_id' => User::factory(),
            'is_active' => true,
        ];
    }
}

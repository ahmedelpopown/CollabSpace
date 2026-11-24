<?php

namespace Modules\AI\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AI\Models\AIConversation;
use Modules\Identity\Models\User;

/** @extends Factory<AIConversation> */
class AIConversationFactory extends Factory
{
    protected $model = AIConversation::class;

    public function definition(): array
    {
        return [
            'created_by_user_id' => User::factory(),
            'title' => fake()->sentence(),
            'context_metadata' => [],
        ];
    }
}

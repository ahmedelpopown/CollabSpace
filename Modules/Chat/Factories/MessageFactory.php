<?php

namespace Modules\Chat\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Chat\Models\Message;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'sender_user_id' => null,
            'body' => fake()->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Message $message) {
            $sender = $message->sender ?? User::factory()->create();

            if (! $sender->hasActiveProjectMembership((int) $message->project_id)) {
                $message->project->members()->syncWithoutDetaching([
                    $sender->getKey() => [
                        'membership_status' => 'active',
                        'added_by_user_id' => $sender->getKey(),
                        'joined_at' => now(),
                    ],
                ]);
            }

            $message->forceFill(['sender_user_id' => $sender->getKey()])->save();
        });
    }
}

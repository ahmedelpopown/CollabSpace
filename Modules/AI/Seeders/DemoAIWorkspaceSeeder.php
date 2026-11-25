<?php

namespace Modules\AI\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\AI\Models\AIConversation;
use Modules\AI\Models\AIMessage;
use Modules\Projects\Models\Project;

class DemoAIWorkspaceSeeder extends Seeder
{
    private const CONVERSATIONS_PER_PROJECT = 2;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        // AI remains disabled by default: demo conversations do not require a live provider/API key.
        $projects = Project::query()->with('activeMembers')->get();

        DB::transaction(function () use ($projects): void {
            foreach ($projects as $project) {
                $members = $project->activeMembers->values();
                if ($members->isEmpty()) {
                    continue;
                }

                for ($number = 1; $number <= self::CONVERSATIONS_PER_PROJECT; $number++) {
                    $creator = $members[($number - 1) % $members->count()];
                    $title = sprintf('%s AI Workspace Conversation %02d', $project->name, $number);

                    $conversation = AIConversation::query()->firstOrCreate(
                        [
                            'created_by_user_id' => $creator->getKey(),
                            'project_id' => $project->getKey(),
                            'title' => $title,
                        ],
                        ['context_metadata' => ['source' => 'demo-seeder', 'project_name' => $project->name]],
                    );

                    $prompts = [
                        'Summarize the current project progress and list the main blockers.',
                        'Which tasks should the team prioritize before the next milestone?',
                    ];

                    foreach ($prompts as $index => $prompt) {
                        AIMessage::query()->firstOrCreate(
                            [
                                'conversation_id' => $conversation->getKey(),
                                'sender_type' => 'user',
                                'content' => $prompt,
                            ],
                            [
                                'sender_user_id' => $creator->getKey(),
                                'model_name' => null,
                                'metadata' => ['source' => 'demo-seeder'],
                                'input_tokens' => 24 + ($index * 8),
                                'output_tokens' => null,
                                'created_at' => now()->subDays($number),
                            ],
                        );

                        AIMessage::query()->firstOrCreate(
                            [
                                'conversation_id' => $conversation->getKey(),
                                'sender_type' => 'assistant',
                                'content' => "Demo response: review the active tasks, confirm ownership, and resolve the highest-priority blockers in {$project->name}.",
                            ],
                            [
                                'sender_user_id' => null,
                                'model_name' => 'demo-placeholder',
                                'metadata' => ['source' => 'demo-seeder', 'not_from_live_provider' => true],
                                'input_tokens' => 24 + ($index * 8),
                                'output_tokens' => 38,
                                'created_at' => now()->subDays($number)->addMinutes(1),
                            ],
                        );
                    }

                    DB::table('ai_activity_logs')->updateOrInsert(
                        [
                            'user_id' => $creator->getKey(),
                            'conversation_id' => $conversation->getKey(),
                            'action' => 'demo.conversation.seeded',
                        ],
                        [
                            'resource_type' => 'project',
                            'resource_id' => $project->getKey(),
                            'metadata' => json_encode(['source' => 'demo-seeder'], JSON_THROW_ON_ERROR),
                            'created_at' => now(),
                        ],
                    );
                }
            }
        });
    }

    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo AI data may only be seeded in local or testing environments.');
        }
    }
}

<?php

namespace Modules\Chat\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Chat\Models\Message;
use Modules\Projects\Models\Project;

class DemoChatSeeder extends Seeder
{
    private const MESSAGES_PER_PROJECT = 20;

    private const MESSAGES = [
        'I have shared the latest update for this item.',
        'The first implementation is ready for review.',
        'Can we confirm the acceptance criteria before moving forward?',
        'I added the required notes to the project files.',
        'The team has completed the initial review.',
        'Please check the latest design and leave your feedback.',
        'The API integration is progressing as planned.',
        'We should cover this flow in the next regression cycle.',
    ];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $projects = Project::query()->with(['activeMembers', 'files'])->get();

        DB::transaction(function () use ($projects): void {
            foreach ($projects as $project) {
                $members = $project->activeMembers->values();
                if ($members->isEmpty()) {
                    continue;
                }

                $previousMessage = null;
                for ($number = 1; $number <= self::MESSAGES_PER_PROJECT; $number++) {
                    $sender = $members[($number - 1) % $members->count()];
                    $body = sprintf(
                        '[Demo %s #%02d] %s',
                        $project->name,
                        $number,
                        self::MESSAGES[($number - 1) % count(self::MESSAGES)],
                    );

                    $message = Message::withTrashed()->firstOrNew([
                        'project_id' => $project->getKey(),
                        'body' => $body,
                    ]);

                    if ($message->trashed()) {
                        $message->restore();
                    }

                    $message->forceFill([
                        'project_id' => $project->getKey(),
                        'sender_user_id' => $sender->getKey(),
                        'parent_message_id' => $number % 5 === 0 ? $previousMessage?->getKey() : null,
                        'body' => $body,
                        'edited_at' => $number % 7 === 0 ? now()->subHours($number) : null,
                    ])->save();

                    if ($number % 4 === 0 && $project->files->isNotEmpty()) {
                        $file = $project->files[($number - 1) % $project->files->count()];
                        $message->files()->syncWithoutDetaching([$file->getKey()]);
                    }

                    if ($members->count() > 1 && $number % 3 === 0) {
                        $mentioned = $members->first(fn ($member) => $member->getKey() !== $sender->getKey());
                        if ($mentioned) {
                            $message->mentions()->syncWithoutDetaching([$mentioned->getKey()]);
                        }
                    }

                    if ($number % 2 === 0) {
                        $readerIds = $members->take(min(3, $members->count()))->modelKeys();
                        $message->readers()->syncWithoutDetaching($readerIds);
                    }

                    $previousMessage = $message;
                }
            }
        });
    }

    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo chat data may only be seeded in local or testing environments.');
        }
    }
}

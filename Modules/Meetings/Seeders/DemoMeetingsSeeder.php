<?php

namespace Modules\Meetings\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Meetings\Models\Meeting;
use Modules\Projects\Models\Project;

class DemoMeetingsSeeder extends Seeder
{
    private const MEETINGS_PER_PROJECT = 5;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $projects = Project::query()->with(['creator', 'activeMembers', 'teams.activeMembers', 'tasks'])->get();

        DB::transaction(function () use ($projects): void {
            foreach ($projects as $project) {
                $projectMemberIds = $project->activeMembers->modelKeys();
                $eligiblePairs = collect();

                foreach ($project->teams as $team) {
                    $team->activeMembers
                        ->whereIn('id', $projectMemberIds)
                        ->each(fn ($member) => $eligiblePairs->push([
                            'user' => $member,
                            'team_id' => $team->getKey(),
                        ]));
                }

                $eligiblePairs = $eligiblePairs
                    ->unique(fn (array $pair) => $pair['user']->getKey())
                    ->values();

                if ($eligiblePairs->isEmpty()) {
                    continue;
                }

                for ($number = 1; $number <= self::MEETINGS_PER_PROJECT; $number++) {
                    $startsAt = $number % 2 === 0
                        ? now()->subDays($number)->setTime(11, 0)
                        : now()->addDays($number)->setTime(14, 0);

                    $meeting = Meeting::query()->firstOrNew([
                        'project_id' => $project->getKey(),
                        'title' => sprintf('%s - Demo Sprint Meeting %02d', $project->name, $number),
                    ]);

                    $meeting->forceFill([
                        'project_id' => $project->getKey(),
                        'created_by_user_id' => $project->created_by_user_id,
                        'title' => sprintf('%s - Demo Sprint Meeting %02d', $project->name, $number),
                        'description' => 'Seeded project meeting for testing invitation, attendance, summaries, and action items.',
                        'starts_at' => $startsAt,
                        'ends_at' => $startsAt->copy()->addHour(),
                        'meeting_url' => 'https://meet.example.test/collabspace/'.$project->getKey().'/'.$number,
                        'status' => $startsAt->isPast() ? 'completed' : 'scheduled',
                        'cancelled_at' => null,
                    ])->save();

                    foreach ($eligiblePairs->shuffle()->take(min(4, $eligiblePairs->count())) as $index => $pair) {
                        DB::table('meeting_participants')->updateOrInsert(
                            ['meeting_id' => $meeting->getKey(), 'user_id' => $pair['user']->getKey()],
                            [
                                'eligible_team_id' => $pair['team_id'],
                                'invited_by_user_id' => $project->created_by_user_id,
                                'response_status' => $index % 3 === 0 ? 'accepted' : 'pending',
                                'eligibility_checked_at' => now(),
                                'access_revoked_at' => null,
                                'joined_at' => $index % 3 === 0 && $startsAt->isPast() ? $startsAt->copy()->addMinutes(2) : null,
                                'left_at' => null,
                                'invited_at' => now()->subDays($number),
                            ],
                        );
                    }

                    $summaryText = "Demo summary for {$meeting->title}. The team reviewed progress, discussed blockers, and agreed on the next delivery steps.";
                    $summary = $meeting->summaries()->firstOrCreate(
                        ['summary_text' => $summaryText],
                        [
                            'generated_by_user_id' => $project->created_by_user_id,
                            'model_name' => 'demo-summary-model',
                            'generated_at' => $startsAt->copy()->addMinutes(55),
                        ],
                    );

                    $summary->decisions()->firstOrCreate(
                        ['decision_text' => 'Continue with the current sprint scope and review open blockers.'],
                        ['created_at' => $startsAt],
                    );

                    $summary->risks()->firstOrCreate(
                        ['risk_text' => 'A dependency may affect the planned delivery date.'],
                        [
                            'owner_user_id' => $project->created_by_user_id,
                            'severity' => 'medium',
                            'created_at' => $startsAt,
                        ],
                    );

                    $summary->nextSteps()->firstOrCreate(
                        ['step_text' => 'Update the task board before the next meeting.'],
                        ['created_at' => $startsAt],
                    );

                    $task = $project->tasks->first();
                    $linkedTask = $project->tasks->get(($number - 1) % max(1, $project->tasks->count()));
                    $summary->actionItems()->firstOrCreate(
                        ['title' => "Follow up {$project->name} sprint action {$number}"],
                        [
                            'assigned_to_user_id' => $eligiblePairs->first()['user']->getKey(),
                            'linked_task_id' => $linkedTask?->getKey(),
                            'description' => 'Seeded meeting action item.',
                            'due_at' => $startsAt->copy()->addDays(3),
                            'status' => $number % 2 === 0 ? 'completed' : 'pending',
                        ],
                    );
                }
            }
        });
    }

    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo meetings may only be seeded in local or testing environments.');
        }
    }
}

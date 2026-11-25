<?php

namespace Modules\Tasks\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

class DemoTasksSeeder extends Seeder
{
    private const TASKS_PER_PROJECT = 30;

    private const TITLES = [
        'Create wireframes', 'User research analysis', 'Design system updates',
        'Chat screen prototyping', 'Create micro interaction', 'Accessibility audit',
        'Mobile responsiveness', 'Login screen', 'Survey and feedback',
        'API integration', 'Database optimization', 'Regression testing',
        'Prepare release notes', 'Review onboarding flow', 'Improve dashboard layout',
    ];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $projects = Project::query()
            ->with(['creator', 'activeMembers', 'teams.activeMembers'])
            ->get();

        DB::transaction(function () use ($projects): void {
            foreach ($projects as $project) {
                $members = $project->activeMembers
                    ->filter(fn ($user) => $user->is_active && ! $user->trashed())
                    ->values();

                if ($members->isEmpty()) {
                    throw new LogicException("Project {$project->getKey()} has no active project members.");
                }

                $teams = $project->teams
                    ->filter(fn ($team) => $team->is_active && ! $team->trashed())
                    ->values();

                for ($number = 1; $number <= self::TASKS_PER_PROJECT; $number++) {
                    $status = Task::STATUSES[($number - 1) % count(Task::STATUSES)];
                    $priority = Task::PRIORITIES[($number * 7) % count(Task::PRIORITIES)];
                    $team = $teams->isNotEmpty() && $number % 5 !== 0
                        ? $teams[($number - 1) % $teams->count()]
                        : null;

                    $eligibleMembers = $team
                        ? $members->filter(fn ($member) => $team->activeMembers->contains('id', $member->getKey()))->values()
                        : $members;

                    if ($eligibleMembers->isEmpty()) {
                        $team = null;
                        $eligibleMembers = $members;
                    }

                    $assignees = $eligibleMembers
                        ->shuffle()
                        ->take(min($eligibleMembers->count(), ($number % 3) + 1));

                    $title = sprintf('%s %02d', self::TITLES[($number - 1) % count(self::TITLES)], $number);
                    $task = Task::withTrashed()->firstOrNew([
                        'project_id' => $project->getKey(),
                        'title' => $title,
                    ]);

                    if ($task->trashed()) {
                        $task->restore();
                    }

                    $progress = match ($status) {
                        'pending' => 0,
                        'in_progress' => [25, 35, 50, 62, 75][($number - 1) % 5],
                        'in_review' => [80, 85, 90, 95][($number - 1) % 4],
                        'completed' => 100,
                    };

                    $task->forceFill([
                        'project_id' => $project->getKey(),
                        'assigned_team_id' => $team?->getKey(),
                        'created_by_user_id' => $project->created_by_user_id ?? $members->first()->getKey(),
                        'title' => $title,
                        'description' => "Demo task {$number} for {$project->name}. This record is seeded to exercise the Kanban board, assignment filters, priorities, and progress indicators.",
                        'status' => $status,
                        'priority' => $priority,
                        'progress_percentage' => $progress,
                        'due_at' => now()->addDays(($number % 24) - 8),
                        'completed_at' => $status === 'completed' ? now()->subDays($number % 12) : null,
                    ])->save();

                    $pivot = [];
                    foreach ($assignees as $assignee) {
                        $pivot[$assignee->getKey()] = [
                            'assigned_by_user_id' => $project->created_by_user_id ?? $members->first()->getKey(),
                            'assigned_at' => now()->subDays($number % 10),
                        ];
                    }

                    $task->assignees()->sync($pivot);
                }
            }
        });
    }

    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo tasks may only be seeded in local or testing environments.');
        }
    }
}

<?php

namespace Modules\Projects\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Identity\Models\User;
use Modules\Identity\Seeders\DemoUsersSeeder;
use Modules\Projects\Models\Project;
use Modules\Teams\Models\Team;

class DemoProjectsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo projects may only be seeded in local or testing environments.');
        }

        $this->seedAlphaProject();
        $this->seedSepetGoProject();
        $this->seedAdditionalProjects();
    }


    private function seedAdditionalProjects(): void
    {
        $definitions = [
            [
                'name' => 'Orbit Workspace', 'creator' => 5,
                'members' => [5, 7, 8, 11, 12, 17, 18, 25, 26, 27, 28],
                'teams' => ['Backend', 'Frontend'], 'priority' => 'high', 'status' => 'in_progress',
                'starts' => 28, 'deadline' => 21,
                'description' => 'A collaboration workspace for product delivery, task planning, and team coordination.',
            ],
            [
                'name' => 'Nova Mobile', 'creator' => 6,
                'members' => [6, 3, 4, 11, 12, 13, 33, 34, 35, 36],
                'teams' => ['UI/UX', 'QA'], 'priority' => 'medium', 'status' => 'in_progress',
                'starts' => 20, 'deadline' => 35,
                'description' => 'A mobile experience project focused on usability, accessibility, and release readiness.',
            ],
            [
                'name' => 'Atlas CRM', 'creator' => 7,
                'members' => [7, 4, 9, 17, 18, 19, 41, 42, 43, 44],
                'teams' => ['Backend', 'Product & Delivery'], 'priority' => 'high', 'status' => 'pending',
                'starts' => 5, 'deadline' => 48,
                'description' => 'A customer relationship management platform with sales workflows and reporting.',
            ],
            [
                'name' => 'Pulse Analytics', 'creator' => 8,
                'members' => [8, 4, 7, 17, 18, 33, 34, 35, 36],
                'teams' => ['QA', 'Backend'], 'priority' => 'low', 'status' => 'in_progress',
                'starts' => 38, 'deadline' => 18,
                'description' => 'An analytics workspace for operational metrics, dashboards, and quality insights.',
            ],
            [
                'name' => 'Horizon Portal', 'creator' => 9,
                'members' => [9, 3, 5, 6, 11, 12, 25, 26, 27, 28],
                'teams' => ['UI/UX', 'Frontend'], 'priority' => 'medium', 'status' => 'completed',
                'starts' => 75, 'deadline' => -8,
                'description' => 'A self-service customer portal with responsive screens and streamlined workflows.',
            ],
            [
                'name' => 'Vertex Delivery', 'creator' => 10,
                'members' => [10, 4, 7, 9, 17, 18, 41, 42, 43, 44],
                'teams' => ['Product & Delivery', 'Backend'], 'priority' => 'high', 'status' => 'in_progress',
                'starts' => 32, 'deadline' => 12,
                'description' => 'A delivery operations project coordinating planning, execution, and release validation.',
            ],
        ];

        foreach ($definitions as $definition) {
            DB::transaction(function () use ($definition): void {
                $creator = $this->user($definition['creator']);

                $project = Project::query()->updateOrCreate(
                    ['name' => $definition['name']],
                    [
                        'description' => $definition['description'],
                        'created_by_user_id' => $creator->getKey(),
                        'starts_on' => today()->subDays($definition['starts'])->toDateString(),
                        'deadline' => today()->addDays($definition['deadline'])->toDateString(),
                        'priority' => $definition['priority'],
                        'status' => $definition['status'],
                    ],
                );

                $project->forceFill(['created_by_user_id' => $creator->getKey()])->save();
                $this->syncProjectMembers($project, $creator, $definition['members']);
                $this->syncProjectTeams($project, $creator, $definition['teams']);
            });
        }
    }

    private function seedAlphaProject(): void
    {
        DB::transaction(function (): void {
            $creator = $this->user(3);

            $project = Project::query()->updateOrCreate(
                ['name' => 'Alpha'],
                [
                    'description' => 'A product design and delivery project focused on organizing tasks, tracking progress, and coordinating team efforts.',
                    'created_by_user_id' => $creator->getKey(),
                    'starts_on' => today()->subDays(14)->toDateString(),
                    'deadline' => today()->addDays(30)->toDateString(),
                    'priority' => 'high',
                    'status' => 'in_progress',
                ],
            );

            // created_by_user_id is intentionally guarded on the Project model.
            $project->forceFill(['created_by_user_id' => $creator->getKey()])->save();

            $this->syncProjectMembers($project, $creator, [3, 4, 5, 6, 11, 12, 17, 18]);
            $this->syncProjectTeams($project, $creator, ['UI/UX', 'Backend']);
        });
    }

    private function seedSepetGoProject(): void
    {
        DB::transaction(function (): void {
            $creator = $this->user(4);

            $project = Project::query()->updateOrCreate(
                ['name' => 'SepetGo'],
                [
                    'description' => 'An e-commerce delivery experience covering customer journeys, application workflows, and release quality.',
                    'created_by_user_id' => $creator->getKey(),
                    'starts_on' => today()->subDays(45)->toDateString(),
                    'deadline' => today()->subDays(8)->toDateString(),
                    'priority' => 'medium',
                    'status' => 'completed',
                ],
            );

            $project->forceFill(['created_by_user_id' => $creator->getKey()])->save();

            $this->syncProjectMembers($project, $creator, [4, 7, 8, 9, 23, 24, 25, 33, 34, 35]);
            $this->syncProjectTeams($project, $creator, ['Frontend', 'QA', 'Product & Delivery']);
        });
    }

    /**
     * Project membership is explicit: assigning a team never auto-adds its members.
     *
     * @param array<int> $memberNumbers
     */
    private function syncProjectMembers(Project $project, User $creator, array $memberNumbers): void
    {
        $members = [];

        foreach (array_values(array_unique(array_merge([$this->numberFor($creator)], $memberNumbers))) as $number) {
            $member = $this->user($number);
            $members[$member->getKey()] = [
                'membership_status' => 'active',
                'added_by_user_id' => $creator->getKey(),
                'invited_at' => null,
                'joined_at' => now(),
                'removed_at' => null,
            ];
        }

        $project->members()->sync($members);
    }

    /**
     * @param array<string> $teamNames
     */
    private function syncProjectTeams(Project $project, User $assignedBy, array $teamNames): void
    {
        $teams = Team::query()
            ->whereIn('name', $teamNames)
            ->where('is_active', true)
            ->get()
            ->keyBy('name');

        if ($teams->count() !== count($teamNames)) {
            throw new LogicException('Run DemoTeamsSeeder before DemoProjectsSeeder.');
        }

        $assignments = [];

        foreach ($teamNames as $teamName) {
            $team = $teams->get($teamName);
            $assignments[$team->getKey()] = [
                'assigned_by_user_id' => $assignedBy->getKey(),
                'assigned_at' => now(),
            ];
        }

        $project->teams()->sync($assignments);
    }

    private function user(int $number): User
    {
        return User::query()
            ->where('email', DemoUsersSeeder::emailFor($number))
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function numberFor(User $user): int
    {
        $email = $user->email;

        if (! preg_match('/^demo(\d{3})@collabspace\.test$/', $email, $matches)) {
            throw new LogicException('Project creator is not a seeded demo account.');
        }

        return (int) $matches[1];
    }
}

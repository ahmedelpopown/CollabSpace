<?php

namespace Modules\Teams\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Identity\Models\User;
use Modules\Identity\Seeders\DemoUsersSeeder;
use Modules\Teams\Models\Team;

class DemoTeamsSeeder extends Seeder
{
    private const TEAMS = [
        [
            'name' => 'UI/UX',
            'description' => 'User research, product experience, interface design, and design systems.',
            'owner' => 3,
            'members' => [3, 4, 5, 6, 11, 12, 13, 14, 15, 16],
        ],
        [
            'name' => 'Backend',
            'description' => 'API development, application architecture, integrations, and data management.',
            'owner' => 4,
            'members' => [4, 7, 17, 18, 19, 20, 21, 22, 23, 24],
        ],
        [
            'name' => 'Frontend',
            'description' => 'Web application interfaces, accessibility, and frontend integration.',
            'owner' => 7,
            'members' => [7, 8, 25, 26, 27, 28, 29, 30, 31, 32],
        ],
        [
            'name' => 'QA',
            'description' => 'Quality assurance, test planning, regression testing, and release validation.',
            'owner' => 8,
            'members' => [8, 9, 33, 34, 35, 36, 37, 38, 39, 40],
        ],
        [
            'name' => 'Product & Delivery',
            'description' => 'Product planning, delivery coordination, and cross-team communication.',
            'owner' => 9,
            'members' => [9, 10, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50],
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo teams may only be seeded in local or testing environments.');
        }

        DB::transaction(function (): void {
            foreach (self::TEAMS as $definition) {
                $owner = User::query()->findOrFail(
                    $this->userId($definition['owner']),
                );

                $team = Team::query()->updateOrCreate(
                    ['name' => $definition['name']],
                    [
                        'description' => $definition['description'],
                        'owner_user_id' => $owner->getKey(),
                        'is_active' => true,
                    ],
                );

                $members = [];

                foreach (array_values(array_unique($definition['members'])) as $number) {
                    $userId = $this->userId($number);
                    $members[$userId] = [
                        'team_role' => $number === $definition['owner'] ? 'lead' : 'member',
                        'membership_status' => 'active',
                        'added_by_user_id' => $owner->getKey(),
                        'joined_at' => now(),
                        'left_at' => null,
                    ];
                }

                // Team membership is seeded independently from project membership.
                $team->members()->sync($members);
            }
        });
    }

    private function userId(int $number): int
    {
        return (int) User::query()
            ->where('email', DemoUsersSeeder::emailFor($number))
            ->firstOrFail()
            ->getKey();
    }
}

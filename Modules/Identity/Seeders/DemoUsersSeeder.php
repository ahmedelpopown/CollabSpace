<?php

namespace Modules\Identity\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Identity\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DemoUsersSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'CollabSpace@2026';

    private const USERS = [
        'Mohamed Salah',
        'Omar Nabil',
        'Mohanad Wahib',
        'Sara Hassan',
        'Ali Mohamed',
        'Khalid Mousa',
        'Youssef Adel',
        'Nourhan Ahmed',
        'Mariam Samir',
        'Karim Tarek',
        'Hana Mahmoud',
        'Mostafa Ibrahim',
        'Salma Hany',
        'Amr Wael',
        'Farah Ashraf',
        'Ziad Hassan',
        'Malak Ahmed',
        'Omar Fathy',
        'Yara Mohamed',
        'Mahmoud Samy',
        'Nada Tamer',
        'Adam Khaled',
        'Jana Mostafa',
        'Hossam Atef',
        'Aya Sherif',
        'Tarek Gamal',
        'Mina Nader',
        'Rana Emad',
        'Hassan Medhat',
        'Laila Ayman',
        'Bassem Ehab',
        'Dina Wael',
        'Fady Samir',
        'Reem Hossam',
        'Ibrahim Adel',
        'Nour Amr',
        'Habiba Tarek',
        'Seif Mohamed',
        'Menna Ashraf',
        'Ramy Sameh',
        'Dalia Ahmed',
        'Peter Wael',
        'Shahd Karim',
        'Ayman Salah',
        'Mai Hany',
        'Tamer Nabil',
        'Nada Ibrahim',
        'Othman Ali',
        'Riham Mostafa',
        'Fares Mahmoud',
    ];

    private const JOB_TITLES = [
        'UI/UX Designer',
        'Backend Developer',
        'Frontend Developer',
        'QA Engineer',
        'Product Specialist',
        'Business Analyst',
    ];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['Administrator', 'Project Manager', 'Team Member'])
            ->get()
            ->keyBy('name');

        if ($roles->count() !== 3) {
            throw new LogicException(
                'Run AccessControlSeeder before DemoUsersSeeder. Required roles: Administrator, Project Manager, Team Member.',
            );
        }

        DB::transaction(function () use ($roles): void {
            foreach (self::USERS as $offset => $fullName) {
                $number = $offset + 1;
                $roleName = $this->roleFor($number);
                $jobTitle = match ($roleName) {
                    'Administrator' => 'System Administrator',
                    'Project Manager' => 'Project Manager',
                    default => self::JOB_TITLES[($number - 1) % count(self::JOB_TITLES)],
                };

                $user = User::query()->updateOrCreate(
                    ['email' => self::emailFor($number)],
                    [
                        'full_name' => $fullName,
                        // User's hashed cast hashes this plain value exactly once.
                        'password' => self::DEMO_PASSWORD,
                        'job_title' => $jobTitle,
                        'years_experience' => $roleName === 'Administrator'
                            ? 8
                            : ($roleName === 'Project Manager' ? 5 : (($number % 7) + 1)),
                        'email_verified_at' => now(),
                        'is_active' => true,
                    ],
                );

                // One role per demo account keeps the permission boundaries meaningful.
                $user->syncRoles([$roles->get($roleName)]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function emailFor(int $number): string
    {
        return sprintf('demo%03d@collabspace.test', $number);
    }

    private function roleFor(int $number): string
    {
        return match (true) {
            $number <= 2 => 'Administrator',
            $number <= 10 => 'Project Manager',
            default => 'Team Member',
        };
    }

    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo users may only be seeded in local or testing environments.');
        }
    }
}

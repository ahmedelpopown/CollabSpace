<?php

namespace Modules\Audit\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Audit\Models\AuditLog;
use Modules\Identity\Models\AuthenticationLog;
use Modules\Identity\Models\User;

class DemoAuditSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo audit data may only be seeded in local or testing environments.');
        }

        $users = User::query()->where('is_active', true)->get();

        DB::transaction(function () use ($users): void {
            foreach ($users as $user) {
                AuthenticationLog::query()->firstOrCreate(
                    [
                        'attempted_email' => $user->email,
                        'login_method' => 'demo_seed',
                        'was_successful' => true,
                    ],
                    [
                        'user_id' => $user->getKey(),
                        'failure_reason' => null,
                        'ip_address' => '127.0.0.1',
                        'user_agent' => 'CollabSpace Demo Seeder',
                        'created_at' => now()->subDays($user->getKey() % 14),
                    ],
                );

                AuditLog::query()->firstOrCreate(
                    [
                        'actor_user_id' => $user->getKey(),
                        'action' => 'demo.account.activity',
                        'subject_type' => User::class,
                        'subject_id' => $user->getKey(),
                    ],
                    [
                        'old_values' => null,
                        'new_values' => ['source' => 'demo-seeder', 'account_status' => 'active'],
                        'ip_address' => '127.0.0.1',
                        'user_agent' => 'CollabSpace Demo Seeder',
                        'created_at' => now()->subDays($user->getKey() % 14),
                    ],
                );
            }
        });
    }
}

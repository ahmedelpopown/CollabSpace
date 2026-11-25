<?php

namespace Modules\Notifications\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Identity\Models\User;
use Modules\Notifications\Models\Notification;

class DemoNotificationsSeeder extends Seeder
{
    private const NOTIFICATIONS_PER_USER = 3;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo notifications may only be seeded in local or testing environments.');
        }

        $users = User::query()->where('is_active', true)->get();

        DB::transaction(function () use ($users): void {
            foreach ($users as $user) {
                for ($number = 1; $number <= self::NOTIFICATIONS_PER_USER; $number++) {
                    $type = sprintf('demo.workspace.activity.%02d', $number);
                    Notification::query()->firstOrCreate(
                        ['user_id' => $user->getKey(), 'type' => $type],
                        [
                            'data' => [
                                'seeded' => true,
                                'title' => match ($number) {
                                    1 => 'Project activity',
                                    2 => 'Task assignment',
                                    default => 'Team update',
                                },
                                'message' => 'This is a sample notification for local API and UI testing.',
                                'seed_key' => sprintf('user-%d-notification-%d', $user->getKey(), $number),
                            ],
                            'read_at' => $number === 1 ? null : now()->subHours($number),
                        ],
                    );
                }
            }
        });
    }
}

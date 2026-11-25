<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Modules\AI\Models\AISetting;
use Modules\AI\Seeders\AIAccessPolicySeeder;
use Modules\Identity\Seeders\DemoUsersSeeder;
use Modules\Tasks\Seeders\DemoTasksSeeder;
use Modules\Files\Seeders\DemoFilesSeeder;
use Modules\Chat\Seeders\DemoChatSeeder;
use Modules\Meetings\Seeders\DemoMeetingsSeeder;
use Modules\AI\Seeders\DemoAIWorkspaceSeeder;
use Modules\Reports\Seeders\DemoReportsSeeder;
use Modules\Notifications\Seeders\DemoNotificationsSeeder;
use Modules\Audit\Seeders\DemoAuditSeeder;
use Modules\Projects\Seeders\DemoProjectsSeeder;
use Modules\Teams\Seeders\DemoTeamsSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccessControlSeeder::class);

        AISetting::query()->updateOrCreate(
            ['id' => 1],
            ['is_enabled' => false, 'temperature' => 0.70],
        );

        $this->call(AIAccessPolicySeeder::class);

        // Demo accounts use a shared local-only password; never create them in production.
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                DemoUsersSeeder::class,
                DemoTeamsSeeder::class,
                DemoProjectsSeeder::class,
                DemoTasksSeeder::class,
                DemoFilesSeeder::class,
                DemoChatSeeder::class,
                DemoMeetingsSeeder::class,
                DemoAIWorkspaceSeeder::class,
                DemoReportsSeeder::class,
                DemoNotificationsSeeder::class,
                DemoAuditSeeder::class,
            ]);
        }
    }
}

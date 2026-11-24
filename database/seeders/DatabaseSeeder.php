<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Modules\AI\Models\AISetting;
use Modules\AI\Seeders\AIAccessPolicySeeder;

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
    }
}

<?php

namespace Modules\Reports\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Identity\Models\User;
use Modules\Reports\Models\ReportExport;

/** @extends Factory<ReportExport> */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'created_by_user_id' => User::factory(),
            'report_type' => 'user',
            'parameters' => [],
            'status' => 'queued',
            'created_at' => now(),
        ];
    }
}

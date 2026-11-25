<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('priority', 20)->default('medium')->after('status');
            $table->unsignedTinyInteger('progress_percentage')->default(0)->after('priority');
            $table->index(['project_id', 'priority'], 'tasks_project_priority_index');
        });

        DB::table('tasks')
            ->where('status', 'completed')
            ->update([
                'progress_percentage' => 100,
                'completed_at' => DB::raw('COALESCE(completed_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_project_priority_index');
            $table->dropColumn(['priority', 'progress_percentage']);
        });
    }
};

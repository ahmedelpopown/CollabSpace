<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index(
                ['project_id', 'assigned_team_id', 'status', 'due_at'],
                'tasks_project_team_status_due_index',
            );
        });

        Schema::table('task_assignees', function (Blueprint $table): void {
            // The composite index also covers user_id-only lookups.
            $table->index(['user_id', 'task_id'], 'task_assignees_user_task_index');
            $table->dropIndex('task_assignees_user_id_index');
        });

        Schema::table('project_members', function (Blueprint $table): void {
            $table->index(
                ['project_id', 'membership_status', 'user_id'],
                'project_members_project_status_user_index',
            );
        });

        Schema::table('team_members', function (Blueprint $table): void {
            $table->index(
                ['team_id', 'membership_status', 'user_id'],
                'team_members_team_status_user_index',
            );
        });

        Schema::table('project_teams', function (Blueprint $table): void {
            $table->index(['team_id', 'project_id'], 'project_teams_team_project_index');
        });

        Schema::table('project_files', function (Blueprint $table): void {
            $table->index(
                ['project_id', 'attached_at', 'file_id'],
                'project_files_project_attached_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('project_files', function (Blueprint $table): void {
            $table->dropIndex('project_files_project_attached_index');
        });

        Schema::table('project_teams', function (Blueprint $table): void {
            $table->dropIndex('project_teams_team_project_index');
        });

        Schema::table('team_members', function (Blueprint $table): void {
            $table->dropIndex('team_members_team_status_user_index');
        });

        Schema::table('project_members', function (Blueprint $table): void {
            $table->dropIndex('project_members_project_status_user_index');
        });

        Schema::table('task_assignees', function (Blueprint $table): void {
            $table->dropIndex('task_assignees_user_task_index');
            $table->index('user_id');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_project_team_status_due_index');
        });
    }
};

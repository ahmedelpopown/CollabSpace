<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('meeting_url')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'starts_at']);
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('eligible_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('response_status', 20)->default('pending');
            $table->dateTime('eligibility_checked_at');
            $table->timestamp('access_revoked_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('invited_at')->useCurrent();
            $table->primary(['meeting_id', 'user_id']);
            $table->index(['user_id', 'access_revoked_at']);
        });

        Schema::create('meeting_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('model_name')->nullable();
            $table->longText('summary_text');
            $table->dateTime('generated_at');
        });

        Schema::create('meeting_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('summary_id')->constrained('meeting_summaries')->cascadeOnDelete();
            $table->text('decision_text');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('meeting_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('summary_id')->constrained('meeting_summaries')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('risk_text');
            $table->string('severity', 20);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('meeting_next_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('summary_id')->constrained('meeting_summaries')->cascadeOnDelete();
            $table->text('step_text');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('meeting_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('summary_id')->constrained('meeting_summaries')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('linked_task_id')->nullable()->unique()->constrained('tasks')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('status', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_action_items');
        Schema::dropIfExists('meeting_next_steps');
        Schema::dropIfExists('meeting_risks');
        Schema::dropIfExists('meeting_decisions');
        Schema::dropIfExists('meeting_summaries');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};

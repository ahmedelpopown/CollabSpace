<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('job_title', 120)->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('oauth_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_user_id', 191);
            $table->string('provider_email')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });

        Schema::create('authentication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attempted_email')->nullable();
            $table->string('login_method', 30);
            $table->boolean('was_successful');
            $table->string('failure_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['attempted_email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_logs');
        Schema::dropIfExists('oauth_accounts');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};

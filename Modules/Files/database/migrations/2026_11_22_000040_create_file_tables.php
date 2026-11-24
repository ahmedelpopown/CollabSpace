<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('storage_disk', 80);
            $table->string('storage_path', 500);
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_files', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attached_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attached_at')->useCurrent();
            $table->primary(['project_id', 'file_id']);
        });

        Schema::create('task_files', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attached_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attached_at')->useCurrent();
            $table->primary(['task_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_files');
        Schema::dropIfExists('project_files');
        Schema::dropIfExists('files');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::rename('ai_runs', 'task_runs');
        Schema::rename('ai_run_logs', 'task_run_logs');

        Schema::table('task_run_logs', function (Blueprint $table): void {
            $table->renameColumn('ai_run_id', 'task_run_id');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['pull_request_url', 'pull_request_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('pull_request_url')->nullable();
            $table->unsignedBigInteger('pull_request_number')->nullable();
        });

        Schema::table('task_runs', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('task_run_logs', function (Blueprint $table): void {
            $table->renameColumn('task_run_id', 'ai_run_id');
        });

        Schema::rename('task_run_logs', 'ai_run_logs');
        Schema::rename('task_runs', 'ai_runs');
    }
};

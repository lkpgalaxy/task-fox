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
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('project_id')
                ->nullable()
                ->after('source_input_id')
                ->constrained('projects')
                ->nullOnDelete();
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreignId('project_id')
                ->nullable()
                ->after('task_id')
                ->constrained('projects')
                ->nullOnDelete();
            $table->string('workspace_path')->nullable()->after('repository_path');
            $table->string('base_branch')->nullable()->after('workspace_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropForeignIdFor('project_id');
            $table->dropColumn(['project_id', 'workspace_path', 'base_branch']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeignIdFor('project_id');
            $table->dropColumn('project_id');
        });
    }
};

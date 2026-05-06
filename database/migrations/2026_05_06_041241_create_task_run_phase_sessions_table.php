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
        Schema::create('task_run_phase_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_run_id')->constrained('task_runs')->cascadeOnDelete();
            $table->string('phase');
            $table->string('status')->default('pending');
            $table->string('session_id')->nullable();
            $table->text('resume_command')->nullable();
            $table->string('model')->nullable();
            $table->string('reasoning_effort')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->decimal('total_cost_usd', 12, 8)->nullable();
            $table->json('command')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['task_run_id', 'phase']);
            $table->index(['phase', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_run_phase_sessions');
    }
};

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
        Schema::create('ai_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->cascadeOnDelete();
            $table->foreignId('input_source_id')->nullable()->constrained('input_sources')->cascadeOnDelete();
            $table->string('level');
            $table->longText('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['ai_run_id']);
            $table->index(['input_source_id']);
            $table->index(['level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_run_logs');
    }
};

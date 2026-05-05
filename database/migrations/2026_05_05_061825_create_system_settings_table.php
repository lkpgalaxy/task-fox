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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('analyze_source_model')->nullable()->default('gpt-5.4');
            $table->string('analyze_source_reasoning_effort')->nullable()->default('medium');
            $table->string('plan_model')->nullable()->default('gpt-5.5');
            $table->string('plan_reasoning_effort')->nullable()->default('high');
            $table->string('implement_model')->nullable()->default('gpt-5.5');
            $table->string('implement_reasoning_effort')->nullable()->default('medium');
            $table->string('review_model')->nullable()->default('gpt-5.5');
            $table->string('review_reasoning_effort')->nullable()->default('high');
            $table->string('commit_message_model')->nullable()->default('gpt-5.4-mini');
            $table->string('commit_message_reasoning_effort')->nullable()->default('medium');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};

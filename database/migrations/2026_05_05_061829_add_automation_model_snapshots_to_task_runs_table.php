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
        Schema::table('task_runs', function (Blueprint $table) {
            $table->string('analyze_source_model')->nullable()->default(null)->after('workflow_state');
            $table->string('analyze_source_reasoning_effort')->nullable()->default(null)->after('analyze_source_model');
            $table->string('plan_model')->nullable()->default(null)->after('analyze_source_reasoning_effort');
            $table->string('plan_reasoning_effort')->nullable()->default(null)->after('plan_model');
            $table->string('implement_model')->nullable()->default(null)->after('plan_reasoning_effort');
            $table->string('implement_reasoning_effort')->nullable()->default(null)->after('implement_model');
            $table->string('review_model')->nullable()->default(null)->after('implement_reasoning_effort');
            $table->string('review_reasoning_effort')->nullable()->default(null)->after('review_model');
            $table->string('commit_message_model')->nullable()->default(null)->after('review_reasoning_effort');
            $table->string('commit_message_reasoning_effort')->nullable()->default(null)->after('commit_message_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn([
                'analyze_source_model',
                'analyze_source_reasoning_effort',
                'plan_model',
                'plan_reasoning_effort',
                'implement_model',
                'implement_reasoning_effort',
                'review_model',
                'review_reasoning_effort',
                'commit_message_model',
                'commit_message_reasoning_effort',
            ]);
        });
    }
};

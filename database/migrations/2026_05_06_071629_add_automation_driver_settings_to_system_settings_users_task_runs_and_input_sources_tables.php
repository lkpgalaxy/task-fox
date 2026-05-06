<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->string('agent_driver')->nullable()->after('id');
            $table->string('coding_agent_driver')->nullable()->after('agent_driver');
            $table->string('external_task_provider')->nullable()->after('coding_agent_driver');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('automation_agent_driver')->nullable()->after('disabled_at');
            $table->string('automation_coding_agent_driver')->nullable()->after('automation_agent_driver');
            $table->string('automation_external_task_provider')->nullable()->after('automation_coding_agent_driver');
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->string('coding_agent_driver')->nullable()->after('finished_at');
        });

        Schema::table('input_sources', function (Blueprint $table) {
            $table->string('agent_driver')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('input_sources', function (Blueprint $table) {
            $table->dropColumn('agent_driver');
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('coding_agent_driver');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'automation_agent_driver',
                'automation_coding_agent_driver',
                'automation_external_task_provider',
            ]);
        });

        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'agent_driver',
                'coding_agent_driver',
                'external_task_provider',
            ]);
        });
    }
};

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
        Schema::table('system_settings', function (Blueprint $table) {
            $table->integer('retry_limit')->nullable()->default(3)->after('commit_message_reasoning_effort');
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->integer('retry_limit')->nullable()->default(3)->after('commit_message_reasoning_effort');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('retry_limit');
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('retry_limit');
        });
    }
};

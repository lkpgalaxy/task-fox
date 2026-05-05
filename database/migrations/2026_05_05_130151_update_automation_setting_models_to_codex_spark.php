<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODEX_SPARK_MODEL = 'gpt-5.3-codex-spark';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('system_settings')->update([
            'analyze_source_model' => self::CODEX_SPARK_MODEL,
            'plan_model' => self::CODEX_SPARK_MODEL,
            'implement_model' => self::CODEX_SPARK_MODEL,
            'review_model' => self::CODEX_SPARK_MODEL,
            'commit_message_model' => self::CODEX_SPARK_MODEL,
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->update([
            'analyze_source_model' => 'gpt-5.4',
            'plan_model' => 'gpt-5.5',
            'implement_model' => 'gpt-5.5',
            'review_model' => 'gpt-5.5',
            'commit_message_model' => 'gpt-5.4-mini',
            'updated_at' => now(),
        ]);
    }
};

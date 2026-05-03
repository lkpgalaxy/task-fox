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
        Schema::table('ai_run_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_run_logs', 'input_source_id')) {
                $table->foreignId('input_source_id')
                    ->nullable()
                    ->after('ai_run_id')
                    ->constrained('input_sources')
                    ->cascadeOnDelete();

                $table->index(['input_source_id']);
            }

            $table->foreignId('ai_run_id')
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_run_logs', function (Blueprint $table) {
            if (Schema::hasColumn('ai_run_logs', 'input_source_id')) {
                $table->dropConstrainedForeignId('input_source_id');
            }

            $table->foreignId('ai_run_id')
                ->nullable(false)
                ->change();
        });
    }
};

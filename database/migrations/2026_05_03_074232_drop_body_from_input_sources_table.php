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
        Schema::table('input_sources', function (Blueprint $table) {
            if (! Schema::hasColumn('input_sources', 'analysis_result')) {
                $table->json('analysis_result')->nullable()->after('file_size');
            }

            if (Schema::hasColumn('input_sources', 'body')) {
                $table->dropColumn('body');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('input_sources', function (Blueprint $table) {
            if (Schema::hasColumn('input_sources', 'analysis_result')) {
                $table->dropColumn('analysis_result');
            }

            if (! Schema::hasColumn('input_sources', 'body')) {
                $table->longText('body')->after('file_size')->default('');
            }
        });
    }
};

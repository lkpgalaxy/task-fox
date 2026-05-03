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
        Schema::table('input_sources', function (Blueprint $table): void {
            if (Schema::hasColumn('input_sources', 'original_filename') && ! Schema::hasColumn('input_sources', 'filename')) {
                $table->renameColumn('original_filename', 'filename');
            }
        });

        if (Schema::hasColumn('input_sources', 'project_id')) {
            $hasProjectForeignKey = collect(Schema::getForeignKeys('input_sources'))
                ->contains(fn (array $foreignKey): bool => in_array('project_id', $foreignKey['columns'], true));

            Schema::table('input_sources', function (Blueprint $table) use ($hasProjectForeignKey): void {
                if ($hasProjectForeignKey) {
                    $table->dropForeign(['project_id']);
                }

                $table->dropColumn('project_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('input_sources', function (Blueprint $table): void {
            if (Schema::hasColumn('input_sources', 'filename') && ! Schema::hasColumn('input_sources', 'original_filename')) {
                $table->renameColumn('filename', 'original_filename');
            }
        });
    }
};

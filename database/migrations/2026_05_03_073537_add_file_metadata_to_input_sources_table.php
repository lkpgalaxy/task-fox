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
            $table->string('file_disk')->nullable()->after('original_filename');
            $table->string('file_path')->nullable()->after('file_disk');
            $table->string('mime_type')->nullable()->after('file_path');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('input_sources', function (Blueprint $table) {
            $table->dropColumn([
                'file_disk',
                'file_path',
                'mime_type',
                'file_size',
            ]);
        });
    }
};

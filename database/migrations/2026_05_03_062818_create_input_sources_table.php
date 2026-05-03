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
        Schema::create('input_sources', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_filename')->nullable();
            $table->json('analysis_result')->nullable();
            $table->string('analysis_status')->default('pending');
            $table->text('last_analysis_error')->nullable();
            $table->timestamps();

            $table->index(['analysis_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('input_sources');
    }
};

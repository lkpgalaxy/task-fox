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
        Schema::create('ai_model_pricings', function (Blueprint $table) {
            $table->id();
            $table->string('model')->unique();
            $table->string('display_name');
            $table->decimal('input_per_million_usd', 10, 6);
            $table->decimal('cached_input_per_million_usd', 10, 6);
            $table->decimal('output_per_million_usd', 10, 6);
            $table->string('pricing_mode')->default('standard');
            $table->string('source_url');
            $table->timestamp('verified_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_model_pricings');
    }
};

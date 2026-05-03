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
        Schema::create('external_task_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_task_link_id')->constrained('external_task_links')->cascadeOnDelete();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['external_task_link_id']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_task_messages');
    }
};

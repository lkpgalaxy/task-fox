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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('workspace_path');
            $table->string('url')->nullable();
            $table->string('database_name')->nullable();
            $table->string('database_username')->nullable();
            $table->text('database_password')->nullable();
            $table->string('credential_username')->nullable();
            $table->text('credential_password')->nullable();
            $table->string('base_branch')->nullable();
            $table->timestamps();

            $table->index(['name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

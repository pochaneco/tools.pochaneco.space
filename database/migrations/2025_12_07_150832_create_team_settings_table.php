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
        Schema::create('team_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();

            // API Keys (encrypted)
            $table->text('openai_api_key')->nullable();
            $table->text('claude_api_key')->nullable();
            $table->text('google_api_key')->nullable();

            // AI Model settings
            $table->string('ai_model')->nullable(); // e.g., gpt-4, claude-3, gemini-pro
            $table->json('ai_settings')->nullable(); // temperature, max_tokens, etc.

            $table->timestamps();

            // Each team can have only one settings record
            $table->unique('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_settings');
    }
};

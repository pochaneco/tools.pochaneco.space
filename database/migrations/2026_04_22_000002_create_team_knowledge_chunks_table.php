<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_id')->constrained('team_knowledges')->cascadeOnDelete();
            // Denormalized team_id so the similarity scan can filter a single
            // team's chunks without joining team_knowledges on every search.
            $table->foreignUlid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('heading_path', 500)->nullable();
            $table->longText('content');
            $table->unsignedInteger('token_count');
            $table->json('embedding');
            $table->string('embedding_model', 100);
            $table->unsignedSmallInteger('embedding_dims');
            $table->timestamps();

            $table->index(['team_id', 'embedding_model']);
            $table->index(['knowledge_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_knowledge_chunks');
    }
};

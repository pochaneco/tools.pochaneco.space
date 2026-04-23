<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Nullable so existing conversations aren't broken. New chats
            // persist the team they were scoped to, which determines which
            // team's knowledge base the RAG tool can search.
            $table->foreignUlid('team_id')
                ->nullable()
                ->after('user_id')
                ->constrained('teams')
                ->nullOnDelete();

            $table->index(['user_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'team_id']);
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};

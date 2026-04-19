<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a per-message `model` column so each assistant turn can record
     * which AI model produced it. User messages leave this column as NULL
     * (they are not bound to any model); `conversations.model` continues to
     * represent the conversation-level default selection.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('model')->nullable()->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};

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
        Schema::dropIfExists('registration_tokens');
        Schema::dropIfExists('contacts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 元に戻す場合は、元のマイグレーションファイルを参照
        // このマイグレーションは一方向のみ想定
    }
};

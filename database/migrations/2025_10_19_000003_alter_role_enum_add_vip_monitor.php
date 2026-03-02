<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support ENUM or ALTER COLUMN, so skip for SQLite
        if (DB::getDriverName() === 'mysql') {
            $table = DB::getTablePrefix().'users';
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN role ENUM('admin','moderator','user','guest','vip','monitor') NOT NULL DEFAULT 'guest'");
        }
        // For SQLite, the role column is already TEXT and can accept any value
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $table = DB::getTablePrefix().'users';
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN role ENUM('admin','moderator','user','guest') NOT NULL DEFAULT 'guest'");
        }
    }
};

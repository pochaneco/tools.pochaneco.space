<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user from ORENO_EMAIL environment variable
        $adminEmail = env('ORENO_EMAIL', 'admin@example.com');

        if (!User::where('email', $adminEmail)->exists()) {
            User::create([
                'name' => 'Admin',
                'email' => $adminEmail,
                'password' => bcrypt('password'), // Change this or prompt for password
                'email_verified_at' => now(),
                'role' => UserRole::ADMIN,
            ]);

            $this->command->info("Admin user created: {$adminEmail}");
        } else {
            $this->command->info("Admin user already exists: {$adminEmail}");
        }
    }
}

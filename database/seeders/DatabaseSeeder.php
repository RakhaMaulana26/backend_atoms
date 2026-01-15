<?php

namespace Database\Seeders;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create default shifts
        Shift::create(['name' => 'pagi']);
        Shift::create(['name' => 'siang']);
        Shift::create(['name' => 'malam']);

        // Create default admin user
        User::create([
            'name' => 'Administrator',
            'email' => 'admin@airnav.com',
            'role' => 'admin',
            'password' => Hash::make('admin123'),
            'is_active' => true,
        ]);

        $this->command->info('✅ Default shifts created: pagi, siang, malam');
        $this->command->info('✅ Admin user created: admin@airnav.com / admin123');
    }
}


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

        // Role to Employee Type mapping
        $roleToEmployeeTypeMap = [
            User::ROLE_ADMIN => 'Administrator',
            User::ROLE_CNS => 'CNS',
            User::ROLE_SUPPORT => 'Support',
            User::ROLE_MANAGER_TEKNIK => 'Manager Teknik',
            User::ROLE_GENERAL_MANAGER => 'General Manager',
        ];

        // Create users for each role
        $users = [
            [
                'name' => 'Administrator',
                'email' => 'admin@airnav.com',
                'role' => User::ROLE_ADMIN,
                'password' => Hash::make('admin123'),
            ],
            [
                'name' => 'CNS Controller',
                'email' => 'cns@airnav.com',
                'role' => User::ROLE_CNS,
                'password' => Hash::make('cns123'),
            ],
            [
                'name' => 'Support Staff',
                'email' => 'support@airnav.com',
                'role' => User::ROLE_SUPPORT,
                'password' => Hash::make('support123'),
            ],
            [
                'name' => 'Manager Teknik',
                'email' => 'manager.teknik@airnav.com',
                'role' => User::ROLE_MANAGER_TEKNIK,
                'password' => Hash::make('manager123'),
            ],
            [
                'name' => 'General Manager',
                'email' => 'general.manager@airnav.com',
                'role' => User::ROLE_GENERAL_MANAGER,
                'password' => Hash::make('gm123'),
            ],
        ];

        foreach ($users as $userData) {
            $user = User::create(array_merge($userData, ['is_active' => true]));
            
            // Create corresponding employee record with proper employee_type mapping
            $user->employee()->create([
                'employee_type' => $roleToEmployeeTypeMap[$user->role],
                'is_active' => true,
            ]);
        }

        $this->command->info('✅ Default shifts created: pagi, siang, malam');
        $this->command->info('✅ Users created with different roles:');
        $this->command->info('   - Admin: admin@airnav.com / admin123');
        $this->command->info('   - CNS: cns@airnav.com / cns123');
        $this->command->info('   - Support: support@airnav.com / support123');
        $this->command->info('   - Manager Teknik: manager.teknik@airnav.com / manager123');
        $this->command->info('   - General Manager: general.manager@airnav.com / gm123');
    }
}


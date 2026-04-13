<?php

namespace Database\Seeders;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    private int $counter = 1;
    
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create default shifts with time ranges
        $shiftsData = [
            ['name' => 'pagi', 'start_time' => '07:00:00', 'end_time' => '13:00:00'],
            ['name' => 'siang', 'start_time' => '13:00:00', 'end_time' => '19:00:00'],
            ['name' => 'malam', 'start_time' => '19:00:00', 'end_time' => '07:00:00'],
            ['name' => 'libur', 'start_time' => null, 'end_time' => null],
            ['name' => 'cuti_tahunan', 'start_time' => null, 'end_time' => null],
            ['name' => 'cuti_sakit', 'start_time' => null, 'end_time' => null],
            ['name' => 'dinas_luar', 'start_time' => null, 'end_time' => null],
            ['name' => 'office_hour', 'start_time' => '08:00:00', 'end_time' => '17:00:00'],
            ['name' => 'standby', 'start_time' => null, 'end_time' => null],
            ['name' => 'lepas_malam', 'start_time' => null, 'end_time' => null],
            ['name' => 'tugas_belajar', 'start_time' => null, 'end_time' => null],
        ];
        
        foreach ($shiftsData as $shiftData) {
            Shift::updateOrCreate(
                ['name' => $shiftData['name']], 
                $shiftData
            );
        }

        // Create Admin user
        $admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@airnav.com',
            'role' => User::ROLE_ADMIN,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $admin->employee()->create([
            'employee_type' => 'Administrator',
            'is_active' => true,
        ]);

        // ================================
        // GENERAL MANAGER (1 employee)
        // ================================
        $generalManagers = [
            ['name' => 'General Manager', 'kelas' => 17, 'jabatan' => 'General Manager', 'group' => null],
        ];

        // ================================
        // MANAGER TEKNIK (5 employees - Grade 15)
        // Fixed managers - cannot be removed or changed by roster actions
        // ================================
        $managerTeknik = [
            ['name' => 'Dudik Fahrudin Sukarno', 'kelas' => 15, 'jabatan' => 'MT 1', 'group' => 1],
            ['name' => 'Andi Wibowo', 'kelas' => 15, 'jabatan' => 'MT 2', 'group' => 2],
            ['name' => 'Efried Nara Perkasa', 'kelas' => 15, 'jabatan' => 'MT 3', 'group' => 3],
            ['name' => 'Alam Fahmi', 'kelas' => 15, 'jabatan' => 'MT 4', 'group' => 4],
            ['name' => 'Netty Septa Cristila', 'kelas' => 15, 'jabatan' => 'MT 5', 'group' => 5],
        ];

        // ================================
        // CNSD - TELEKOMUNIKASI (31 employees - termasuk Aditya, Moch. Ichsan, Priyoko sebagai SPV)
        // ================================
        $cnsd = [
            // Grup 1 - dengan Aditya sbg SVP CNS (grade 13)
            ['name' => 'Aditya Huzairi P', 'kelas' => 13, 'jabatan' => 'SVP CNS', 'group' => 1],
            ['name' => 'Moch. Ichsan', 'kelas' => 14, 'jabatan' => 'SPV CNS', 'group' => 1],
            ['name' => 'Argo Pragolo', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 1],
            ['name' => 'Khoirul M.A', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 1],
            ['name' => 'Saiful Bahris', 'kelas' => 9, 'jabatan' => 'CNS', 'group' => 1],
            ['name' => 'Silvy Retno Andriani', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 1],
            ['name' => 'Tria Sabda Utama', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 1],
            // Grup 2
            ['name' => 'Febri Dwi C', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 2],
            ['name' => 'M. Yusuf Triono', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 2],
            ['name' => 'Dani Ridzal', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 2],
            ['name' => 'Nur Shella Firdaus', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 2],
            ['name' => 'Amirzan Ridho W', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 2],
            ['name' => 'Erazuardi Zulfahmi', 'kelas' => 8, 'jabatan' => 'CNS', 'group' => 2],
            // Grup 3
            ['name' => 'Nur Hukim', 'kelas' => 14, 'jabatan' => 'SPV CNS', 'group' => 3],
            ['name' => 'Moh. Syamsudin', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 3],
            ['name' => 'Rhomadoni S.K.D', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 3],
            ['name' => 'Yourdan C.P', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 3],
            ['name' => 'Safira Saraswati', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 3],
            ['name' => 'Aldhi Deska P', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 3],
            // Grup 4
            ['name' => 'Riyan Fauzi', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 4],
            ['name' => 'Pandu Indra Jaya', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 4],
            ['name' => 'Elvita Agustina', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 4],
            ['name' => 'Rendy Panca A P', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 4],
            ['name' => 'I Kadek Dwija S', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 4],
            // Grup 5
            ['name' => 'Teguh M', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 5],
            ['name' => 'Yusri H.', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 5],
            ['name' => 'Dwiki Setyo W', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 5],
            ['name' => 'Septi Rahman sari', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 5],
            ['name' => 'Adam bukhori', 'kelas' => 12, 'jabatan' => 'CNS', 'group' => 5],
            ['name' => 'Windi Tri Setyawati', 'kelas' => 11, 'jabatan' => 'CNS', 'group' => 5],
        ];

        // ================================
        // TFP - FASILITAS PENUNJANG (17 employees - termasuk Fajar sbg SPV TFP)
        // ================================
        $tfp = [
            // Grup 1
            ['name' => 'Fajar Kusuma W', 'kelas' => 13, 'jabatan' => 'SPV TFP', 'group' => 1],
            ['name' => 'Iqbal Mustika', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 1],
            ['name' => 'Agustina Anggreini', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 1],
            ['name' => 'Fajar Nugroho', 'kelas' => 10, 'jabatan' => 'TFP', 'group' => 1],
            ['name' => 'Bian Prasetia H', 'kelas' => 8, 'jabatan' => 'TFP', 'group' => 1],
            // Grup 2
            ['name' => 'Sofi Dwi Hidayati', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 2],
            ['name' => 'M. Feizar Noor', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 2],
            ['name' => 'Dwi Prasetyo Adi', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 2],
            // Grup 3
            ['name' => 'Yoga Arifal P', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 3],
            ['name' => 'Ilmin Syarif H', 'kelas' => 9, 'jabatan' => 'TFP', 'group' => 3],
            // Grup 4
            ['name' => 'Dwi Puji Rahayu', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 4],
            ['name' => 'Andhika Bhaskara J', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 4],
            ['name' => 'M. Aidin Effendi', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 4],
            ['name' => 'A. M. Yasin', 'kelas' => 8, 'jabatan' => 'TFP', 'group' => 4],
            // Grup 5
            ['name' => 'Priyoko', 'kelas' => 13, 'jabatan' => 'SPV TFP', 'group' => 5],
            ['name' => 'Frisza Vradana', 'kelas' => 11, 'jabatan' => 'TFP', 'group' => 5],
            ['name' => 'Karang Samudra', 'kelas' => 9, 'jabatan' => 'TFP', 'group' => 5],
        ];

        // Create all employees
        // General Manager
        foreach ($generalManagers as $emp) {
            $this->createEmployee($emp, 'general_manager', false);
        }

        // Manager Teknik - fixed positions, cannot be changed
        foreach ($managerTeknik as $emp) {
            $isFixed = true;
            $this->createEmployee($emp, 'manager_teknik', $isFixed);
        }

        // CNSD - all are CNS
        foreach ($cnsd as $emp) {
            $this->createEmployee($emp, 'cns', false);
        }

        // TFP - all are Support
        foreach ($tfp as $emp) {
            $this->createEmployee($emp, 'support', false);
        }

        $this->command->info('✅ Admin user created: admin@airnav.com / password');
        $this->command->info('✅ Airnav employees created:');
        $this->command->info('   - General Manager: 1 employee');
        $this->command->info('   - Manager Teknik: 5 employees (FIXED - cannot remove):');
        $this->command->info('     • Dudik Fahrudin Sukarno → MT 1, grade 15 ✅ FIXED');
        $this->command->info('     • Andi Wibowo → MT 2, grade 15 ✅ FIXED');
        $this->command->info('     • Efried Nara Perkasa → MT 3, grade 15 ✅ FIXED');
        $this->command->info('     • Alam Fahmi → MT 4, grade 15 ✅ FIXED');
        $this->command->info('     • Netty Septa Cristila → MT 5, grade 15 ✅ FIXED');
        $this->command->info('   - CNSD Telekomunikasi: 31 employees (CNS)');
        $this->command->info('     • Aditya Huzairi P → CNS, grade 13 (SVP CNS)');
        $this->command->info('     • Moch. Ichsan, Nur Hukim → SPV CNS');
        $this->command->info('   - TFP Fasilitas Penunjang: 17 employees (Support)');
        $this->command->info('     • Fajar Kusuma W → Support, grade 13 (SPV TFP)');
        $this->command->info('     • Priyoko → SPV TFP');
        $this->command->info('   Total: 54 employees + 1 admin = 55 users');
        $this->command->info('✅ All users password: password');
        $this->command->info('✅ Email format: user1@airnav.com, user2@airnav.com, ...');
        $this->command->info('✅ Groups hanya digunakan dalam konteks rostering');
    }

    private function createEmployee(array $data, string $type, bool $isFixedManager = false): void
    {
        // Generate simple email: user1@airnav.com, user2@airnav.com, etc.
        $email = 'user' . $this->counter . '@airnav.com';
        $this->counter++;
        
        // Set role and employee type based on parameter
        switch ($type) {
            case 'general_manager':
                $role = User::ROLE_GENERAL_MANAGER;
                $employeeType = 'General Manager';
                break;
            case 'manager_teknik':
                $role = User::ROLE_MANAGER_TEKNIK;
                $employeeType = 'Manager Teknik';
                break;
            case 'support':
                $role = User::ROLE_SUPPORT;
                $employeeType = 'Support';
                break;
            case 'cns':
            default:
                $role = User::ROLE_CNS;
                $employeeType = 'CNS';
                break;
        }

        $defaultGroupNumber = $data['group'] ?? null;
        if ($type === 'manager_teknik' && $defaultGroupNumber === null && isset($data['jabatan']) && preg_match('/^MT\s*(\d+)$/i', $data['jabatan'], $matches)) {
            $defaultGroupNumber = (int) $matches[1];
        }

        // Create user with same password for all
        $user = User::create([
            'name' => $data['name'],
            'email' => $email,
            'role' => $role,
            'password' => Hash::make('password'),
            'is_active' => true,
            'grade' => $data['kelas'] ?? null,
        ]);

        // Create employee record
        $user->employee()->create([
            'employee_type' => $employeeType,
            'is_active' => true,
            'group_number' => $defaultGroupNumber,
            'is_fixed_manager' => $isFixedManager,
        ]);
    }
}


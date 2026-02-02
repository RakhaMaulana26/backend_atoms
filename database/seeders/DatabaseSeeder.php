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
        // Create default shifts
        $shifts = ['pagi', 'siang', 'malam', 'libur', 'cuti_tahunan', 'cuti_sakit', 'dinas_luar', 'office_hour', 'standby', 'lepas_malam', 'tugas_belajar'];
        foreach ($shifts as $shiftName) {
            Shift::firstOrCreate(['name' => $shiftName]);
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
        // TEKNIK MT & PT MT (5 employees)
        // ================================
        $teknikMT = [
            ['name' => 'Aditya Huzairi P', 'kelas' => 13, 'jabatan' => 'SVP CNS'],
            ['name' => 'Andi Wibowo', 'kelas' => 15, 'jabatan' => 'MT 2'],
            ['name' => 'Efried N.P.', 'kelas' => 15, 'jabatan' => 'MT 3'],
            ['name' => 'Fajar Kusuma W', 'kelas' => 13, 'jabatan' => 'SPV TFP'],
            ['name' => 'Netty Septa C.', 'kelas' => 15, 'jabatan' => 'MT 5'],
        ];

        // ================================
        // CNSD - TELEKOMUNIKASI (30 employees)
        // ================================
        $cnsd = [
            // Grup 1
            ['name' => 'Moch. Ichsan', 'kelas' => 14, 'jabatan' => 'FIRST SPV CNS'],
            ['name' => 'Argo Pragolo', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Khoirul M.A', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Saiful Bahris', 'kelas' => 9, 'jabatan' => 'CNS'],
            ['name' => 'Silvy Retno Andriani', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Tria Sabda Utama', 'kelas' => 11, 'jabatan' => 'CNS'],
            // Grup 2
            ['name' => 'Febri Dwi C', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'M. Yusuf Triono', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Dani Ridzal', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Nur Shella Firdaus', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Amirzan Ridho W', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Erazuardi Zulfahmi', 'kelas' => 8, 'jabatan' => 'CNS'],
            // Grup 3
            ['name' => 'Nur Hukim', 'kelas' => 14, 'jabatan' => 'SPV CNS'],
            ['name' => 'Moh. Syamsudin', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Rhomadoni S.K.D', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Yourdan C.P', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Safira Saraswati', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Aldhi Deska P', 'kelas' => 11, 'jabatan' => 'CNS'],
            // Grup 4
            ['name' => 'Riyan Fauzi', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Pandu Indra Jaya', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Elvita Agustina', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Rendy Panca A P', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'I Kadek Dwija S', 'kelas' => 11, 'jabatan' => 'CNS'],
            // Grup 5
            ['name' => 'Teguh M', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Yusri H.', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Dwiki Setyo W', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Septi Rahman sari', 'kelas' => 11, 'jabatan' => 'CNS'],
            ['name' => 'Adam bukhori', 'kelas' => 12, 'jabatan' => 'CNS'],
            ['name' => 'Windi Tri Setyawati', 'kelas' => 11, 'jabatan' => 'CNS'],
        ];

        // ================================
        // TFP - FASILITAS PENUNJANG (16 employees)
        // ================================
        $tfp = [
            // Grup 1
            ['name' => 'Iqbal Mustika', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'Agustina Anggreini', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'Fajar Nugroho', 'kelas' => 10, 'jabatan' => 'TFP'],
            ['name' => 'Bian Prasetia H', 'kelas' => 8, 'jabatan' => 'TFP'],
            // Grup 2
            ['name' => 'Sofi Dwi Hidayati', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'M. Feizar Noor', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'Dwi Prasetyo Adi', 'kelas' => 11, 'jabatan' => 'TFP'],
            // Grup 3
            ['name' => 'Yoga Arifal P', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'Ilmin Syarif H', 'kelas' => 9, 'jabatan' => 'TFP'],
            // Grup 4
            ['name' => 'Dwi Puji Rahayu', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'Andhika Bhaskara J', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'M. Aidin Effendi', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'A. M. Yasin', 'kelas' => 8, 'jabatan' => 'TFP'],
            // Grup 5
            ['name' => 'Priyoko', 'kelas' => 13, 'jabatan' => 'SPV TFP'],
            ['name' => 'Frisza Vradana', 'kelas' => 11, 'jabatan' => 'TFP'],
            ['name' => 'Karang Samudra', 'kelas' => 9, 'jabatan' => 'TFP'],
        ];

        // Create all employees
        // Teknik MT - all are Manager Teknik
        foreach ($teknikMT as $emp) {
            $this->createEmployee($emp, 'manager_teknik');
        }

        // CNSD - all are CNS
        foreach ($cnsd as $emp) {
            $this->createEmployee($emp, 'cns');
        }

        // TFP - all are Support
        foreach ($tfp as $emp) {
            $this->createEmployee($emp, 'support');
        }

        $this->command->info('✅ Default shifts created: ' . implode(', ', $shifts));
        $this->command->info('✅ Admin user created: admin@airnav.com / password');
        $this->command->info('✅ Airnav employees created:');
        $this->command->info('   - Teknik MT & PT MT: 5 employees (Manager Teknik)');
        $this->command->info('   - CNSD Telekomunikasi: 29 employees (CNS)');
        $this->command->info('   - TFP Fasilitas Penunjang: 16 employees (Support)');
        $this->command->info('   Total: 50 employees + 1 admin = 51 users');
        $this->command->info('✅ All users password: password');
        $this->command->info('✅ Email format: user1@airnav.com, user2@airnav.com, ...');
    }

    private function createEmployee(array $data, string $type): void
    {
        // Generate simple email: user1@airnav.com, user2@airnav.com, etc.
        $email = 'user' . $this->counter . '@airnav.com';
        $this->counter++;
        
        // Set role and employee type based on parameter
        switch ($type) {
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
        ]);
    }
}


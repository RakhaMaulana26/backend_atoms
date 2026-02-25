<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
        
        echo "✅ Shifts updated successfully with time ranges\n";
    }
}

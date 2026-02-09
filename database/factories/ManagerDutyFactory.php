<?php

namespace Database\Factories;

use App\Models\ManagerDuty;
use App\Models\RosterDay;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class ManagerDutyFactory extends Factory
{
    protected $model = ManagerDuty::class;

    public function definition(): array
    {
        return [
            'roster_day_id' => RosterDay::factory(),
            'employee_id' => Employee::factory(),
            'duty_type' => fake()->randomElement(['Manager Teknik', 'General Manager']),
            'shift_id' => \App\Models\Shift::factory(),
        ];
    }
}

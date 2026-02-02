<?php

namespace Database\Factories;

use App\Models\ShiftAssignment;
use App\Models\RosterDay;
use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftAssignmentFactory extends Factory
{
    protected $model = ShiftAssignment::class;

    public function definition(): array
    {
        return [
            'roster_day_id' => RosterDay::factory(),
            'employee_id' => Employee::factory(),
            'shift_id' => Shift::factory(),
        ];
    }
}

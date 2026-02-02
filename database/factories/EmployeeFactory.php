<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_type' => fake()->randomElement([Employee::TYPE_CNS, Employee::TYPE_SUPPORT, Employee::TYPE_MANAGER_TEKNIK]),
            'is_active' => true,
        ];
    }
}

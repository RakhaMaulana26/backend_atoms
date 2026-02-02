<?php

namespace Database\Factories;

use App\Models\RosterPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterPeriodFactory extends Factory
{
    protected $model = RosterPeriod::class;

    public function definition(): array
    {
        return [
            'month' => fake()->numberBetween(1, 12),
            'year' => fake()->numberBetween(2024, 2026),
            'status' => 'draft',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\RosterDay;
use App\Models\RosterPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterDayFactory extends Factory
{
    protected $model = RosterDay::class;

    public function definition(): array
    {
        return [
            'roster_period_id' => RosterPeriod::factory(),
            'work_date' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
        ];
    }
}

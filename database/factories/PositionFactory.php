<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Position> */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        return ['department_id' => Department::factory(), 'name' => fake()->unique()->jobTitle()];
    }
}

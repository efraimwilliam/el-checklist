<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'external_id' => fake()->unique()->numberBetween(1, 100000),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->unique()->words(2, true),
        ];
    }
}

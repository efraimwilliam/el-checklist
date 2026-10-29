<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'external_id' => fake()->unique()->numberBetween(1, 1000000),
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'department_id' => Department::factory(),
            'position_title' => fake()->jobTitle(),
            'is_active' => true,
        ];
    }
}

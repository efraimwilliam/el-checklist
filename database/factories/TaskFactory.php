<?php

namespace Database\Factories;

use App\Models\Position;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return ['position_id' => Position::factory(), 'title' => fake()->sentence(4), 'standard' => fake()->sentence()];
    }
}

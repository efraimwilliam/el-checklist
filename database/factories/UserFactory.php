<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return ['employee_id' => Employee::factory(), 'role' => Role::Staff, 'is_active' => true];
    }

    public function role(Role $role): static
    {
        return $this->state(['role' => $role]);
    }

    /** HOD yang ditugaskan ke departemen ini. */
    public function hodOf(Department ...$departments): static
    {
        return $this->role(Role::Hod)->afterCreating(fn (User $user) => $user->departments()->attach(collect($departments)->pluck('id')));
    }
}

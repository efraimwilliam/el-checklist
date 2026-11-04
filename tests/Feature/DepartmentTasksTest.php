<?php

use App\Checklists\ChecklistService;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;

it('gives every position of a department the same default task list, own position first', function () {
    $department = Department::factory()->create();
    $chief = Position::factory()->for($department)->create(['name' => 'Chief Engineer']);
    $technician = Position::factory()->for($department)->create(['name' => 'Engineering Technician']);
    Task::factory()->for($chief)->create(['title' => 'Cek utilitas']);
    Task::factory()->for($technician)->create(['title' => 'Cek genset']);
    $employee = Employee::factory()->create(['department_id' => $department->id]);
    $service = app(ChecklistService::class);

    expect($service->tasksFor($employee, $chief)->pluck('title')->all())->toBe(['Cek utilitas', 'Cek genset'])
        ->and($service->tasksFor($employee, $technician)->pluck('title')->all())->toBe(['Cek genset', 'Cek utilitas']);
});

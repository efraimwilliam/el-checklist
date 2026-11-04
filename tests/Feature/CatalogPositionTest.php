<?php

use App\Models\Department;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    $this->bellboy = Position::factory()->for($this->fo)->create(['name' => 'Bellboy']);
    $this->ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    $this->task = Task::factory()->for($this->gsa)->create(['title' => 'Check-in tamu']);
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

it('offers department and position dropdowns to filter the task list', function () {
    loggedIn($this->hod);
    $html = $this->get(route('team.catalog'))->assertOk()->getContent();

    expect($html)->toContain('data-dept-select')->toContain('data-position-select')
        ->toContain('data-department-id="'.$this->fo->id.'"')
        ->toContain('data-position-id="'.$this->gsa->id.'"');
});

it('offers a position choice in both the add and the edit task dialogs', function () {
    loggedIn($this->hod);
    $html = $this->get(route('team.catalog'))->assertOk()->getContent();

    expect($html)->toContain('id="dialog-position"')
        ->toContain('id="edit-position"')
        ->toContain('value="'.$this->bellboy->id.'"')
        ->toContain('data-position-id="'.$this->gsa->id.'"')
        ->not->toContain('value="'.$this->ra->id.'"');
});

it('lets a HOD move a task to another position in the same department', function () {
    loggedIn($this->hod);
    $this->put(route('team.catalog.tasks.update', $this->task), [
        'position' => $this->bellboy->id,
        'title' => 'Check-in tamu',
        'parameters' => [['name' => 'Status', 'type' => 'status']],
    ])->assertRedirect();

    expect($this->task->refresh()->position_id)->toBe($this->bellboy->id);
});

it('refuses to move a task into a department the HOD cannot manage', function () {
    loggedIn($this->hod);
    $this->put(route('team.catalog.tasks.update', $this->task), [
        'position' => $this->ra->id,
        'title' => 'Check-in tamu',
        'parameters' => [['name' => 'Status', 'type' => 'status']],
    ])->assertForbidden();

    expect($this->task->refresh()->position_id)->toBe($this->gsa->id);
});

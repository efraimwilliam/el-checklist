<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->position = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    $this->tasks = collect(['Sapa tamu', 'Cek minibar', 'Antar koper'])->map(fn ($title, $i) => Task::factory()->for($this->position)->create(['title' => $title, 'sort' => $i + 1]));
    $this->employee = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso']);
    $this->checklist = makeChecklist($this->employee, $this->position, null, null);
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

it('lists the default tasks of the employee for the day', function () {
    loggedIn($this->hod);

    $this->get(route('checklists.tasks.edit', $this->checklist))->assertOk()
        ->assertSee('Atur tugas hari ini')->assertSee('Sapa tamu')->assertSee('Cek minibar')->assertSee('Antar koper');
});

it('removes an unanswered task from today and adds a default task back with its parameters', function () {
    loggedIn($this->hod);
    $keep = [$this->tasks[0]->id, $this->tasks[2]->id];

    $this->put(route('checklists.tasks.update', $this->checklist), ['tasks' => $keep])->assertRedirect(route('checklists.fill', $this->checklist));
    expect($this->checklist->items()->pluck('task_id')->sort()->values()->all())->toEqualCanonicalizing($keep);

    $this->put(route('checklists.tasks.update', $this->checklist), ['tasks' => [...$keep, $this->tasks[1]->id]]);
    $added = $this->checklist->items()->where('task_id', $this->tasks[1]->id)->first();
    expect($added)->not->toBeNull()->and($added->parameters)->not->toBeEmpty();
});

it('keeps a task that already has an answer, even when it is unticked', function () {
    loggedIn($this->hod);
    $answered = $this->checklist->items()->where('task_id', $this->tasks[1]->id)->first();
    $answered->update(['status' => 'done']);

    $this->put(route('checklists.tasks.update', $this->checklist), ['tasks' => [$this->tasks[0]->id]]);

    expect($this->checklist->items()->where('task_id', $this->tasks[1]->id)->exists())->toBeTrue();
});

it('does not let staff, or a HOD of another department, change the tasks', function () {
    $other = User::factory()->hodOf($this->hk)->create();
    loggedIn($other);
    $this->get(route('checklists.tasks.edit', $this->checklist))->assertForbidden();

    $this->post('/logout');
    loggedIn(User::factory()->for($this->employee)->create(['role' => Role::Staff]));
    $this->put(route('checklists.tasks.update', $this->checklist), ['tasks' => []])->assertForbidden();
});

<?php

use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    $this->bell = Position::factory()->for($this->fo)->create(['name' => 'Bell Boy']);
    $this->sapa = Task::factory()->for($this->gsa)->create(['title' => 'Sapa tamu', 'sort' => 1]);
    $this->koper = Task::factory()->for($this->bell)->create(['title' => 'Antar koper', 'sort' => 1]);
    $this->budi = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso', 'checklist_position_id' => $this->gsa->id]);
    $this->tomorrow = CarbonImmutable::today()->addDay();
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

it('offers a popup with the default tasks and other positions on days other than today', function () {
    loggedIn($this->hod);
    fakeSchedules([[$this->budi, 'work', 'P']]);

    $this->get(route('fill.assign', ['date' => $this->tomorrow->toDateString()]))->assertOk()
        ->assertSee('data-plan-open="'.$this->budi->id.'"', false)
        ->assertSee('Sapa tamu')->assertSee('Antar koper')->assertSee('Bell Boy');
});

it('creates the checklist for the chosen position with the chosen tasks', function () {
    loggedIn($this->hod);

    $this->post(route('fill.plan.start'), [
        'employee' => $this->budi->id,
        'position' => $this->bell->id,
        'date' => $this->tomorrow->toDateString(),
        'tasks' => [$this->koper->id],
    ])->assertRedirect();

    $checklist = Checklist::sole();
    expect($checklist->position_id)->toBe($this->bell->id)
        ->and($checklist->items->pluck('title')->all())->toBe(['Antar koper'])
        ->and($checklist->items->first()->parameters)->not->toBeEmpty();
});

it('lets a checklist include a task from another position in the same department', function () {
    loggedIn($this->hod);

    $this->post(route('fill.plan.start'), [
        'employee' => $this->budi->id,
        'position' => $this->gsa->id,
        'date' => $this->tomorrow->toDateString(),
        'tasks' => [$this->sapa->id, $this->koper->id],
    ])->assertRedirect();

    expect(Checklist::sole()->items->pluck('title')->sort()->values()->all())->toBe(['Antar koper', 'Sapa tamu']);
});

it('ignores tasks from another department', function () {
    loggedIn($this->hod);
    $other = Department::factory()->create(['name' => 'Housekeeping']);
    $foreign = Task::factory()->for(Position::factory()->for($other))->create(['title' => 'Bersihkan kamar']);

    $this->post(route('fill.plan.start'), [
        'employee' => $this->budi->id,
        'position' => $this->gsa->id,
        'date' => $this->tomorrow->toDateString(),
        'tasks' => [$foreign->id],
    ])->assertRedirect();

    expect(Checklist::sole()->items)->toHaveCount(0);
});

it('offers the assign popup for today too, with a confirm button', function () {
    loggedIn($this->hod);
    fakeSchedules([[$this->budi, 'work', 'P']]);

    $this->get(route('fill.assign', ['date' => CarbonImmutable::today()->toDateString()]))->assertOk()
        ->assertSee('data-plan-open="'.$this->budi->id.'"', false)
        ->assertSee('Assign')->assertSee('Konfirmasi')->assertSee('Ubah tugas');
});

it('returns to the assign page after confirming a checklist for today', function () {
    loggedIn($this->hod);
    $today = CarbonImmutable::today()->toDateString();

    $this->post(route('fill.plan.start'), [
        'employee' => $this->budi->id,
        'position' => $this->gsa->id,
        'date' => $today,
        'tasks' => [$this->sapa->id],
    ])->assertRedirect(route('fill.assign', ['date' => $today, 'department' => $this->fo->id]));

    expect(Checklist::sole()->date->toDateString())->toBe($today);
});

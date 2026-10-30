<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->position = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    $this->tasks = collect(['Sapa tamu', 'Cek minibar'])->map(fn ($title, $i) => Task::factory()->for($this->position)->create(['title' => $title, 'sort' => $i + 1]));
    $this->employee = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso']);
    $this->tomorrow = CarbonImmutable::today()->addDay();
    $this->plan = makeChecklist($this->employee, $this->position, $this->tomorrow, null);
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

it('lets the HOD set tasks for a future day without filling anything', function () {
    loggedIn($this->hod);

    $this->get(route('checklists.tasks.edit', $this->plan))->assertOk();
    $this->put(route('checklists.tasks.update', $this->plan), ['tasks' => [$this->tasks[0]->id]])->assertRedirect();

    expect($this->plan->items()->count())->toBe(1);
});

it('blocks filling a future day unless Administrator turned it on', function () {
    loggedIn($this->hod);
    $item = $this->plan->items->first();

    $this->patchJson(route('checklists.items.update', [$this->plan, $item]), ['status' => 'done'])->assertForbidden();

    app(ChecklistSettings::class)->put('checklist_future_fill', '1');
    $this->patchJson(route('checklists.items.update', [$this->plan, $item]), ['status' => 'done'])->assertOk();
});

it('lets Administrator switch the rule on and off, but not HOD', function () {
    $admin = userWithRole(Role::Admin);
    loggedIn($admin);
    $this->put(route('admin.settings.rules.update'), ['future_fill' => '1'])->assertRedirect();
    expect(app(ChecklistSettings::class)->futureFillAllowed())->toBeTrue();

    $this->post('/logout');
    loggedIn($this->hod);
    $this->put(route('admin.settings.rules.update'), ['future_fill' => '0'])->assertForbidden();
});

it('shows staff their planned tasks for the coming days', function () {
    $staff = User::factory()->for($this->employee)->create(['role' => Role::Staff, 'position_id' => $this->position->id]);
    loggedIn($staff);
    fakeSchedules([]);

    $this->get(route('fill.create'))->assertOk()->assertSee('Rencana tugas hari berikutnya')->assertSee('Sapa tamu');
});

it('keeps the assign menu on the assign page when moving to another day', function () {
    loggedIn($this->hod);
    fakeSchedules([]);

    $this->get(route('fill.assign', ['date' => $this->tomorrow->toDateString()]))->assertOk()
        ->assertSee('/checklist/atur?date='.$this->tomorrow->addDay()->toDateString(), false)
        ->assertDontSee('/isi?date=', false);
});

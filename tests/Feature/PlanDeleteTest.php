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
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->position = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    Task::factory()->for($this->position)->create(['title' => 'Sapa tamu', 'sort' => 1]);
    $this->budi = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso']);
    $this->sari = Employee::factory()->for($this->fo)->create(['name' => 'Sari Dewi']);
    $this->tomorrow = CarbonImmutable::today()->addDay();
    $this->budiPlan = makeChecklist($this->budi, $this->position, $this->tomorrow, null);
    $this->sariPlan = makeChecklist($this->sari, $this->position, $this->tomorrow, null);
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

it('deletes an unfilled plan for one employee', function () {
    loggedIn($this->hod);

    $this->delete(route('checklists.plan.destroy', $this->budiPlan))->assertRedirect();

    expect(Checklist::find($this->budiPlan->id))->toBeNull()->and(Checklist::find($this->sariPlan->id))->not->toBeNull();
});

it('keeps a plan that already has entries', function () {
    loggedIn($this->hod);
    $this->budiPlan->items()->update(['status' => 'done']);

    $this->delete(route('checklists.plan.destroy', $this->budiPlan))->assertRedirect()->assertSessionHasErrors('plan');

    expect(Checklist::find($this->budiPlan->id))->not->toBeNull();
});

it('never deletes a checklist for today or an earlier day', function () {
    loggedIn($this->hod);
    $today = makeChecklist($this->budi, $this->position, CarbonImmutable::today(), null);

    $this->delete(route('checklists.plan.destroy', $today))->assertSessionHasErrors('plan');

    expect(Checklist::find($today->id))->not->toBeNull();
});

it('deletes every unfilled plan of one department on a day, and keeps the rest', function () {
    loggedIn($this->hod);
    $this->sariPlan->items()->update(['status' => 'done']);
    $otherDept = Employee::factory()->for($this->hk)->create();
    $other = makeChecklist($otherDept, Position::factory()->for($this->hk)->create(), $this->tomorrow, null);

    $this->delete(route('fill.plan.destroy-day'), ['date' => $this->tomorrow->toDateString(), 'department' => $this->fo->id])->assertRedirect();

    expect(Checklist::find($this->budiPlan->id))->toBeNull()
        ->and(Checklist::find($this->sariPlan->id))->not->toBeNull()
        ->and(Checklist::find($other->id))->not->toBeNull();
});

it('does not let a HOD of another department delete the plan', function () {
    loggedIn(User::factory()->hodOf($this->hk)->create());

    $this->delete(route('checklists.plan.destroy', $this->budiPlan))->assertForbidden();

    expect(Checklist::find($this->budiPlan->id))->not->toBeNull();
});

it('shows the delete buttons on the assign page for future plans', function () {
    loggedIn($this->hod);
    fakeSchedules([]);

    $this->get(route('fill.assign', ['date' => $this->tomorrow->toDateString(), 'department' => $this->fo->id]))->assertOk()
        ->assertSee('Hapus rencana yang belum diisi (2)')->assertSee('Hapus rencana');
});

<?php

use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->position = Position::factory()->for($this->fo)->create(['name' => 'Duty Manager']);
    $this->first = Task::factory()->for($this->position)->create(['title' => 'Take handover', 'sort' => 1]);
    $this->second = Task::factory()->for($this->position)->create(['title' => 'Read email', 'sort' => 2]);
    $this->third = Task::factory()->for($this->position)->create(['title' => 'Check lobby', 'sort' => 3]);
});

function titles(Position $position): array
{
    return $position->tasks()->get()->pluck('title')->all();
}

it('is available to administrators and HR only', function (Role $role, bool $allowed) {
    loggedIn(userWithRole($role));

    $status = $allowed ? 200 : 403;
    $this->get(route('admin.tasks.index'))->assertStatus($status);
    $this->get(route('admin.tasks.show', $this->position))->assertStatus($status);
    $this->post(route('admin.tasks.store', $this->position), ['parameters' => statusParameters(), 'title' => 'Tugas baru'])->assertStatus($allowed ? 302 : 403);
    $this->put(route('admin.tasks.update', $this->first), ['parameters' => statusParameters(), 'title' => 'Diubah'])->assertStatus($allowed ? 302 : 403);
    $this->delete(route('admin.tasks.destroy', $this->first))->assertStatus($allowed ? 302 : 403);
    $this->post(route('admin.positions.store'), ['department_id' => $this->fo->id, 'name' => 'Baru'])->assertStatus($allowed ? 302 : 403);
})->with([
    'admin' => [Role::Admin, true],
    'hr' => [Role::Hr, true],
    'gm' => [Role::Gm, false],
    'hod' => [Role::Hod, false],
    'staff' => [Role::Staff, false],
]);

it('lists departments with their positions and active task counts', function () {
    Task::factory()->for($this->position)->create(['is_active' => false]);
    loggedIn(userWithRole(Role::Hr));

    $this->get(route('admin.tasks.index'))->assertOk()->assertSee('Front Office')->assertSee('Duty Manager')->assertSee('3 tugas aktif');
    $this->get(route('admin.tasks.show', $this->position))->assertOk()->assertSee('Take handover')->assertSee('Check lobby')->assertSee('(nonaktif)');
});

it('adds a task at the end of the list', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->post(route('admin.tasks.store', $this->position), [
        'section' => '  Akhir shift ', 'parameters' => statusParameters(), 'title' => 'Write handover', 'standard' => 'Tulis di logbook', 'remarks' => '',
    ])->assertRedirect();

    $task = Task::where('title', 'Write handover')->sole();
    expect($task)->sort->toBe(4)->section->toBe('Akhir shift')->standard->toBe('Tulis di logbook')->remarks->toBeNull()->is_active->toBeTrue()
        ->and(titles($this->position))->toBe(['Take handover', 'Read email', 'Check lobby', 'Write handover']);
});

it('requires a title', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->post(route('admin.tasks.store', $this->position), ['parameters' => statusParameters(), 'title' => ' '])->assertSessionHasErrors('title');
    expect(Task::count())->toBe(3);
});

it('edits and deactivates a task', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->put(route('admin.tasks.update', $this->second), ['parameters' => statusParameters(), 'title' => 'Read and reply email', 'is_active' => '0'])->assertRedirect(route('admin.tasks.show', $this->position));

    expect($this->second->fresh())->title->toBe('Read and reply email')->is_active->toBeFalse();
});

it('moves tasks up and down, even when imported sort numbers are duplicated', function () {
    Task::whereKey([$this->first->id, $this->second->id, $this->third->id])->update(['sort' => 1]); // seperti data impor yang nomornya ganda
    loggedIn(userWithRole(Role::Admin));

    $this->post(route('admin.tasks.move', $this->third), ['direction' => 'up']);
    expect(titles($this->position))->toBe(['Take handover', 'Check lobby', 'Read email']);

    $this->post(route('admin.tasks.move', $this->first), ['direction' => 'down']);
    expect(titles($this->position))->toBe(['Check lobby', 'Take handover', 'Read email']);

    // Sudah di ujung: tidak berubah dan tidak error.
    $this->post(route('admin.tasks.move', $this->third), ['direction' => 'up'])->assertRedirect(); // teratas
    $this->post(route('admin.tasks.move', $this->second), ['direction' => 'down'])->assertRedirect(); // terbawah
    expect(titles($this->position))->toBe(['Check lobby', 'Take handover', 'Read email']);

    $this->post(route('admin.tasks.move', $this->first), ['direction' => 'sideways'])->assertSessionHasErrors('direction');
});

it('only changes checklists created afterwards, and keeps history when a task is deleted', function () {
    $staff = Employee::factory()->for($this->fo)->create();
    $before = makeChecklist($staff, $this->position, CarbonImmutable::yesterday());
    loggedIn(userWithRole(Role::Admin));

    $this->put(route('admin.tasks.update', $this->first), ['parameters' => statusParameters(), 'title' => 'Diubah', 'is_active' => '0']);
    $this->put(route('admin.tasks.update', $this->second), ['parameters' => statusParameters(), 'title' => 'Read email', 'is_active' => '1', 'standard' => 'Standar baru']);
    $this->delete(route('admin.tasks.destroy', $this->third))->assertRedirect();
    $this->post(route('admin.tasks.store', $this->position), ['parameters' => statusParameters(), 'title' => 'Tugas baru']);

    $after = makeChecklist($staff, $this->position, CarbonImmutable::today());
    expect(Checklist::find($before->id)->items->pluck('title')->all())->toBe(['Take handover', 'Read email', 'Check lobby'])
        ->and($after->items->pluck('title')->all())->toBe(['Read email', 'Tugas baru'])
        ->and($after->items->first()->standard)->toBe('Standar baru');
});

it('adds and renames positions, with unique names per department', function () {
    $hk = Department::factory()->create(['name' => 'Housekeeping']);
    loggedIn(userWithRole(Role::Hr));

    $this->post(route('admin.positions.store'), ['department_id' => $this->fo->id, 'name' => 'Night Auditor'])->assertRedirect();
    $new = Position::where('name', 'Night Auditor')->sole();
    expect($new)->department_id->toBe($this->fo->id)->sort->toBeGreaterThan(0);

    $this->post(route('admin.positions.store'), ['department_id' => $this->fo->id, 'name' => 'Duty Manager'])->assertSessionHasErrors('name');
    $this->post(route('admin.positions.store'), ['department_id' => $hk->id, 'name' => 'Duty Manager'])->assertSessionHasNoErrors(); // beda departemen boleh sama

    $this->put(route('admin.positions.update', $new), ['name' => 'Night Auditor FO', 'is_active' => '0'])->assertRedirect();
    expect($new->fresh())->name->toBe('Night Auditor FO')->is_active->toBeFalse();
    $this->put(route('admin.positions.update', $new), ['name' => 'Duty Manager'])->assertSessionHasErrors('name');
});

it('shows the management link only to those who can manage tasks', function () {
    loggedIn(userWithRole(Role::Hr));
    $this->get(route('checklists.index'))->assertSee('Kelola tugas');

    $this->post('/logout');
    loggedIn(userWithRole(Role::Hod));
    $this->get(route('checklists.index'))->assertDontSee('Kelola tugas');
});

it('moves a task without a page reload when asked for JSON, keeping the order', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->postJson(route('admin.tasks.move', $this->second), ['direction' => 'up'])->assertOk()->assertJsonPath('ok', true);
    expect($this->second->fresh()->sort)->toBeLessThan($this->first->fresh()->sort);
    $this->postJson(route('admin.tasks.move', $this->first), ['direction' => 'sideways'])->assertUnprocessable();
});

it('lets administrators rename, deactivate and delete a position, but keeps one that a checklist has used', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->put(route('admin.positions.update', $this->position), ['name' => 'Duty Manager Senior', 'is_active' => '0'])->assertRedirect();
    expect($this->position->fresh())->name->toBe('Duty Manager Senior')->is_active->toBeFalse();

    $unused = Position::factory()->for($this->fo)->create(['name' => 'Sementara']);
    Task::factory()->for($unused)->create();
    $this->delete(route('admin.positions.destroy', $unused))->assertRedirect(route('admin.tasks.index'));
    expect(Position::find($unused->id))->toBeNull()->and(Task::where('position_id', $unused->id)->exists())->toBeFalse();

    $employee = Employee::factory()->for($this->fo)->create();
    makeChecklist($employee, $this->position);
    $this->delete(route('admin.positions.destroy', $this->position))->assertSessionHasErrors('name');
    expect(Position::find($this->position->id))->not->toBeNull();
});


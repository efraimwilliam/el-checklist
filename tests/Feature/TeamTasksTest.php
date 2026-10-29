<?php

use App\Checklists\ChecklistFactory;
use App\Enums\Role;
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

    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA', 'sort' => 2]);
    $this->gsaTasks = collect(['Check-in tamu', 'Check-out tamu', 'Handover shift'])
        ->map(fn ($title, $i) => Task::factory()->for($this->gsa)->create(['title' => $title, 'sort' => $i + 1]));

    $this->dm = Position::factory()->for($this->fo)->create(['name' => 'Duty Manager', 'sort' => 1]);
    $this->dmTask = Task::factory()->for($this->dm)->create(['title' => 'Review laporan harian', 'sort' => 1]);

    $ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    $this->raTask = Task::factory()->for($ra)->create(['title' => 'Bersihkan kamar']);

    $this->staff = User::factory()->for(Employee::factory()->for($this->fo))->create(['position_id' => $this->gsa->id]);
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

function todaysTitles(User $user): array
{
    // Muat ulang pengguna, seperti permintaan baru: permintaan HTTP di tes mengubah database, bukan objek ini.
    return app(ChecklistFactory::class)->forDate($user->fresh(), CarbonImmutable::today())->items->pluck('title')->all();
}

it('uses all active tasks of the position by default', function () {
    expect(todaysTitles($this->staff))->toBe(['Check-in tamu', 'Check-out tamu', 'Handover shift']);
});

it('lets the HOD choose the tasks, including tasks of other positions in the department', function () {
    loggedIn($this->hod);

    $this->put(route('team.tasks.update', $this->staff), ['tasks' => [$this->dmTask->id, $this->gsaTasks[2]->id, $this->gsaTasks[0]->id]])->assertRedirect(route('team.index'));

    expect($this->staff->fresh()->custom_tasks)->toBeTrue()
        // Tugas jabatan staff sendiri lebih dulu (urutan jabatan), lalu tugas jabatan lain.
        ->and(todaysTitles($this->staff))->toBe(['Check-in tamu', 'Handover shift', 'Review laporan harian'])
        ->and(Checklist::sole()->items->pluck('sort')->all())->toBe([1, 2, 3]);
});

it('does not accept tasks from another department, inactive tasks, or an empty selection', function () {
    $inactive = Task::factory()->for($this->gsa)->create(['is_active' => false]);
    loggedIn($this->hod);

    $this->put(route('team.tasks.update', $this->staff), ['tasks' => [$this->gsaTasks[0]->id, $this->raTask->id]])->assertSessionHasErrors('tasks');
    $this->put(route('team.tasks.update', $this->staff), ['tasks' => [$inactive->id]])->assertSessionHasErrors('tasks');
    $this->put(route('team.tasks.update', $this->staff), ['tasks' => []])->assertSessionHasErrors('tasks');
    $this->put(route('team.tasks.update', $this->staff))->assertSessionHasErrors('tasks');

    expect($this->staff->fresh()->custom_tasks)->toBeFalse()->and($this->staff->assignedTasks()->count())->toBe(0);
});

it('returns to the default tasks of the position on reset', function () {
    $this->staff->assignedTasks()->sync([$this->dmTask->id]);
    $this->staff->update(['custom_tasks' => true]);
    loggedIn($this->hod);

    $this->delete(route('team.tasks.reset', $this->staff))->assertRedirect(route('team.index'));

    expect($this->staff->fresh()->custom_tasks)->toBeFalse()->and($this->staff->assignedTasks()->count())->toBe(0)
        ->and(todaysTitles($this->staff))->toBe(['Check-in tamu', 'Check-out tamu', 'Handover shift']);
});

it('only changes checklists created afterwards', function () {
    $existing = app(ChecklistFactory::class)->forDate($this->staff, CarbonImmutable::yesterday());
    loggedIn($this->hod);

    $this->put(route('team.tasks.update', $this->staff), ['tasks' => [$this->gsaTasks[1]->id]]);

    expect(Checklist::find($existing->id)->items->count())->toBe(3)->and(todaysTitles($this->staff))->toBe(['Check-out tamu']);
});

it('skips assigned tasks that were deactivated or deleted later', function () {
    $this->staff->assignedTasks()->sync([$this->gsaTasks[0]->id, $this->gsaTasks[1]->id, $this->dmTask->id]);
    $this->staff->update(['custom_tasks' => true]);

    $this->gsaTasks[0]->update(['is_active' => false]);
    $this->dmTask->delete();

    expect(todaysTitles($this->staff))->toBe(['Check-out tamu']);
});

it('limits HODs to staff of their own departments', function () {
    $other = User::factory()->for(Employee::factory()->for($this->hk))->create();
    loggedIn($this->hod);

    $this->get(route('team.tasks.edit', $this->staff))->assertOk()->assertSee('Check-in tamu')->assertSee('Review laporan harian')->assertDontSee('Bersihkan kamar');
    $this->get(route('team.tasks.edit', $other))->assertForbidden();
    $this->put(route('team.tasks.update', $other), ['tasks' => [$this->raTask->id]])->assertForbidden();
    $this->delete(route('team.tasks.reset', $other))->assertForbidden();
    expect($other->fresh()->custom_tasks)->toBeFalse();
});

it('does not let a HOD change their own tasks, but an administrator can', function () {
    $own = Position::factory()->for($this->fo)->create();
    $task = Task::factory()->for($own)->create();
    $this->hod->update(['position_id' => $own->id]);
    loggedIn($this->hod);

    $this->get(route('team.tasks.edit', $this->hod))->assertForbidden();
    $this->put(route('team.tasks.update', $this->hod), ['tasks' => [$task->id]])->assertForbidden();

    $this->post('/logout');
    $admin = User::factory()->role(Role::Admin)->for(Employee::factory()->for($this->fo))->create(['position_id' => $own->id]);
    loggedIn($admin);
    $this->get(route('team.tasks.edit', $admin))->assertOk();
});

it('lets HR and administrators assign tasks in any department but not GM or staff', function (Role $role, bool $allowed) {
    loggedIn(userWithRole($role));

    $this->get(route('team.index'))->assertStatus($allowed ? 200 : 403);
    $this->get(route('team.tasks.edit', $this->staff))->assertStatus($allowed ? 200 : 403);
    $this->put(route('team.tasks.update', $this->staff), ['tasks' => [$this->gsaTasks[0]->id]])->assertStatus($allowed ? 302 : 403);
})->with([
    'admin' => [Role::Admin, true],
    'hr' => [Role::Hr, true],
    'gm' => [Role::Gm, false],
    'staff' => [Role::Staff, false],
]);

it('lists only staff the viewer may manage, with their status', function () {
    $other = User::factory()->for(Employee::factory()->for($this->hk))->create();
    $this->staff->assignedTasks()->sync([$this->gsaTasks[0]->id, $this->gsaTasks[1]->id]);
    $this->staff->update(['custom_tasks' => true]);
    loggedIn($this->hod);

    $this->get(route('team.index'))->assertOk()
        ->assertSee($this->staff->name())->assertSee('Disesuaikan · 2 tugas')
        ->assertDontSee($other->name())
        ->assertDontSee(route('team.tasks.edit', $this->hod)); // HOD tidak mengatur dirinya sendiri
});

it('shows the team link to HODs, HR and administrators only', function () {
    loggedIn($this->hod);
    $this->get(route('checklists.index'))->assertSee('Atur tugas tim');

    $this->post('/logout');
    loggedIn(userWithRole(Role::Gm));
    $this->get(route('checklists.index'))->assertDontSee('Atur tugas tim');
});

<?php

use App\Checklists\ChecklistService;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);

    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA', 'sort' => 2]);
    $this->gsaTasks = collect(['Check-in tamu', 'Check-out tamu', 'Handover shift'])
        ->map(fn ($title, $i) => Task::factory()->for($this->gsa)->create(['title' => $title, 'sort' => $i + 1]));
    $this->dm = Position::factory()->for($this->fo)->create(['name' => 'Duty Manager', 'sort' => 1]);
    $this->dmTask = Task::factory()->for($this->dm)->create(['title' => 'Review laporan harian']);
    $ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    $this->raTask = Task::factory()->for($ra)->create(['title' => 'Bersihkan kamar']);

    $this->staff = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso', 'position_title' => 'GSA']);
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

it('lets the HOD choose tasks for staff who have no account, including tasks of other positions in the department', function () {
    loggedIn($this->hod);

    $this->put(route('team.tasks.update', $this->staff), ['tasks' => [$this->dmTask->id, $this->gsaTasks[2]->id, $this->gsaTasks[0]->id]])->assertRedirect(route('team.index'));

    $staff = $this->staff->fresh();
    expect($staff->custom_tasks)->toBeTrue()
        ->and($staff->assignedTasks->pluck('id')->sort()->values()->all())->toBe(collect([$this->dmTask->id, $this->gsaTasks[2]->id, $this->gsaTasks[0]->id])->sort()->values()->all())
        // Jabatan yang dipilih saat mengisi (GSA) lebih dulu, lalu jabatan lain.
        ->and(app(ChecklistService::class)->tasksFor($staff, $this->gsa)->pluck('title')->all())->toBe(['Check-in tamu', 'Handover shift', 'Review laporan harian']);
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

it('shows the saved choice of tasks', function () {
    loggedIn($this->hod);

    $this->get(route('team.tasks.edit', $this->staff))->assertOk()->assertSee('Memakai semua tugas jabatan')->assertDontSee(' checked>')
        ->assertDontSee('Centang semua')->assertDontSee('Isi dari tugas jabatan')->assertSee('Cari tugas');

    $this->staff->assignedTasks()->sync([$this->dmTask->id]);
    $this->staff->update(['custom_tasks' => true]);
    $html = $this->get(route('team.tasks.edit', $this->staff))->assertSee('Tugas sudah disesuaikan')->getContent();
    expect(substr_count($html, ' checked>'))->toBe(1);
});

it('returns to the default tasks of the position on reset', function () {
    $this->staff->assignedTasks()->sync([$this->dmTask->id]);
    $this->staff->update(['custom_tasks' => true]);
    loggedIn($this->hod);

    $this->delete(route('team.tasks.reset', $this->staff))->assertRedirect(route('team.index'));

    expect($this->staff->fresh()->custom_tasks)->toBeFalse()->and($this->staff->assignedTasks()->count())->toBe(0)
        ->and(app(ChecklistService::class)->tasksFor($this->staff->fresh(), $this->gsa)->pluck('title')->all())->toBe(['Check-in tamu', 'Check-out tamu', 'Handover shift']);
});

it('skips assigned tasks that were deactivated or deleted later', function () {
    $this->staff->assignedTasks()->sync([$this->gsaTasks[0]->id, $this->gsaTasks[1]->id, $this->dmTask->id]);
    $this->staff->update(['custom_tasks' => true]);

    $this->gsaTasks[0]->update(['is_active' => false]);
    $this->dmTask->delete();

    expect(app(ChecklistService::class)->tasksFor($this->staff->fresh(), $this->gsa)->pluck('title')->all())->toBe(['Check-out tamu']);
});

it('limits HODs to staff of their own departments and not themselves', function () {
    $other = Employee::factory()->for($this->hk)->create();
    loggedIn($this->hod);

    $this->get(route('team.tasks.edit', $this->staff))->assertOk()->assertSee('Check-in tamu')->assertSee('Review laporan harian')->assertDontSee('Bersihkan kamar');
    $this->get(route('team.tasks.edit', $other))->assertForbidden();
    $this->put(route('team.tasks.update', $other), ['tasks' => [$this->raTask->id]])->assertForbidden();
    $this->delete(route('team.tasks.reset', $other))->assertForbidden();

    $this->hod->employee->update(['department_id' => $this->fo->id]);
    $this->get(route('team.tasks.edit', $this->hod->employee))->assertForbidden();
    expect($other->fresh()->custom_tasks)->toBeFalse();
});

it('lets an administrator change their own tasks, and HR any department, but not GM or staff', function () {
    loggedIn(userWithRole(Role::Admin, ['position_id' => null]));
    $admin = auth()->user();
    $admin->employee->update(['department_id' => $this->fo->id]);
    $this->get(route('team.tasks.edit', $admin->employee))->assertOk();
    $this->post('/logout');

    foreach ([[Role::Hr, 200], [Role::Admin, 200], [Role::Gm, 403], [Role::Staff, 403]] as [$role, $status]) {
        loggedIn(userWithRole($role));
        $this->get(route('team.index'))->assertStatus($status);
        $this->get(route('team.tasks.edit', $this->staff))->assertStatus($status);
        $this->post('/logout');
    }
});

it('lists only staff the viewer may manage, with their status', function () {
    $other = Employee::factory()->for($this->hk)->create(['name' => 'Sari Dewi']);
    Employee::factory()->for($this->fo)->create(['name' => 'Sudah Keluar', 'is_active' => false]);
    $this->staff->assignedTasks()->sync([$this->gsaTasks[0]->id, $this->gsaTasks[1]->id]);
    $this->staff->update(['custom_tasks' => true]);
    loggedIn($this->hod);

    $this->get(route('team.index'))->assertOk()->assertSee('Budi Santoso')->assertSee('Disesuaikan · 2 tugas')
        ->assertDontSee('Sari Dewi')->assertDontSee('Sudah Keluar')->assertDontSee(route('team.tasks.edit', $this->hod->employee));
});

it('shows the team link to HODs, HR and administrators only', function () {
    loggedIn($this->hod);
    $this->get(route('fill.create'))->assertSee('Atur tugas');

    $this->post('/logout');
    loggedIn(userWithRole(Role::Gm));
    $this->get(route('checklists.index'))->assertDontSee('Atur tugas');
});

it('lets the HOD add a task only to positions of their own department', function () {
    loggedIn($this->hod);

    $this->post(route('team.tasks.store'), ['position' => $this->gsa->id, 'parameters' => statusParameters(), 'title' => 'Cek minibar', 'section' => 'Operasional'])
        ->assertRedirect(route('team.catalog').'#jabatan-'.$this->gsa->id);
    expect($this->gsa->tasks()->where('title', 'Cek minibar')->where('section', 'Operasional')->exists())->toBeTrue();
    $this->get(route('team.catalog'))->assertOk()->assertSee('Cek minibar')->assertSee('Daftar tugas');
    $this->get(route('team.index'))->assertOk()->assertDontSee('Cek minibar');

    $this->post(route('team.tasks.store'), ['position' => $this->gsa->id, 'parameters' => statusParameters(), 'title' => ''])->assertSessionHasErrors('title');
    $other = Position::factory()->for(Department::factory()->create())->create();
    $this->post(route('team.tasks.store'), ['position' => $other->id, 'parameters' => statusParameters(), 'title' => 'Tidak boleh'])->assertForbidden();
    expect(Task::where('title', 'Tidak boleh')->exists())->toBeFalse();
});

it('lets the HOD edit and delete tasks of their own department from the catalog', function () {
    loggedIn($this->hod);

    $this->put(route('team.catalog.tasks.update', $this->dmTask), ['parameters' => statusParameters(), 'title' => 'Review laporan harian (revisi)', 'section' => 'Operasional', 'standard' => 'Sebelum 08.00'])
        ->assertRedirect(route('team.catalog').'#jabatan-'.$this->dm->id);
    expect($this->dmTask->fresh())->title->toBe('Review laporan harian (revisi)')->section->toBe('Operasional')->standard->toBe('Sebelum 08.00');
    $this->put(route('team.catalog.tasks.update', $this->dmTask), ['parameters' => statusParameters(), 'title' => ''])->assertSessionHasErrorsIn('editTask', 'title');

    $this->delete(route('team.catalog.tasks.destroy', $this->dmTask))->assertRedirect(route('team.catalog').'#jabatan-'.$this->dm->id);
    expect(Task::find($this->dmTask->id))->toBeNull();

    $other = Task::factory()->for(Position::factory()->for(Department::factory()->create())->create())->create();
    $this->put(route('team.catalog.tasks.update', $other), ['parameters' => statusParameters(), 'title' => 'Tidak boleh'])->assertForbidden();
    $this->delete(route('team.catalog.tasks.destroy', $other))->assertForbidden();
    expect($other->fresh()->title)->not->toBe('Tidak boleh');
});

it('lists every task once, so the browser can sort, search and page without reloading', function () {
    loggedIn($this->hod);
    Task::factory()->for($this->gsa)->count(30)->create(['section' => 'Operasional']);

    $html = $this->get(route('team.catalog'))->assertOk()->assertSee('Handover shift')->getContent();
    expect(substr_count($html, '<tr data-catalog-row'))->toBe(34);
});

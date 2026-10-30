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
    $gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    Task::factory()->for($gsa)->count(2)->create();
    $ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    Task::factory()->for($ra)->create();

    $this->budi = User::factory()->for(Employee::factory()->for($this->fo))->create(['position_id' => $gsa->id]);
    $this->sari = User::factory()->for(Employee::factory()->for($this->hk))->create(['position_id' => $ra->id]);
    $this->budiList = makeChecklist($this->budi->employee, $gsa);
    $this->sariList = makeChecklist($this->sari->employee, $ra);
});

it('does not let staff see or change another employee\'s checklist or open management pages', function () {
    loggedIn($this->budi);

    $this->get(route('checklists.show', $this->budiList))->assertOk();
    $this->get(route('checklists.show', $this->sariList))->assertForbidden();
    $this->patchJson(route('checklists.items.update', [$this->sariList, $this->sariList->items->first()]), ['status' => 'done'])->assertForbidden();
    $this->get(route('checklists.fill', $this->sariList))->assertForbidden();
    $this->post(route('fill.start'), ['position' => $this->sariList->position_id, 'employee' => $this->sariList->employee_id, 'date' => now()->toDateString()])->assertForbidden();
    $this->post(route('checklists.review', $this->sariList))->assertForbidden();
    $this->delete(route('checklists.destroy', $this->sariList))->assertForbidden();
    $this->get(route('checklists.index'))->assertOk()->assertDontSee($this->sari->employee->name);
    $this->get(route('team.index'))->assertForbidden();
    $this->get(route('admin.access.index'))->assertForbidden();
});

it('limits HODs to the departments assigned to them', function () {
    $hod = User::factory()->hodOf($this->fo)->create();
    loggedIn($hod);

    $this->get(route('checklists.show', $this->budiList))->assertOk();
    $this->get(route('checklists.show', $this->sariList))->assertForbidden();
    $this->get(route('checklists.index', ['department' => $this->hk->id]))->assertForbidden();
    $this->get(route('checklists.index'))->assertOk()->assertSee($this->budi->employee->name)->assertDontSee($this->sari->employee->name);
});

it('does not grant HOD access from the attendance department or position alone', function () {
    // HOD tanpa departemen yang ditugaskan, walau pegawainya di Front Office.
    $hod = User::factory()->for(Employee::factory()->for($this->fo)->state(['position_title' => 'Front Office Manager']))->role(Role::Hod)->create();
    loggedIn($hod);

    $this->get(route('checklists.show', $this->budiList))->assertForbidden();
    $this->get(route('fill.create'))->assertOk()->assertDontSee('GSA (');
});

it('lets HR, GM and administrators see every department', function (Role $role) {
    loggedIn(userWithRole($role));

    $this->get(route('checklists.index'))->assertOk()->assertSee($this->budi->employee->name)->assertSee($this->sari->employee->name);
    $this->get(route('checklists.show', $this->sariList))->assertOk();
})->with([Role::Hr, Role::Gm, Role::Admin]);

it('applies revoked roles and departments on the next request', function () {
    $hod = User::factory()->hodOf($this->fo)->create();
    loggedIn($hod);
    $this->get(route('checklists.show', $this->budiList))->assertOk();

    $hod->departments()->detach();
    nextRequest()->get(route('checklists.show', $this->budiList))->assertForbidden();

    $hod->update(['role' => Role::Staff]);
    nextRequest()->get(route('checklists.index'))->assertOk(); // staff: hanya checklist miliknya
});

it('logs out users whose access is revoked or who become inactive at the attendance system', function (Closure $revoke) {
    loggedIn($this->budi);
    $this->get(route('checklists.show', $this->budiList))->assertOk();

    $revoke($this->budi);

    nextRequest()->get(route('checklists.show', $this->budiList))->assertRedirect('/login');
    $this->assertGuest();
})->with([
    'access revoked' => fn (User $user) => $user->update(['is_active' => false]),
    'inactive in attendance' => fn (User $user) => $user->employee->update(['is_active' => false]),
]);

it('only lets administrators manage access, and not remove their own admin role', function () {
    $admin = userWithRole(Role::Admin);
    loggedIn($admin);
    $employee = Employee::factory()->for($this->fo)->create();

    $this->put(route('admin.access.update', $employee), ['is_active' => '1', 'role' => 'hod', 'departments' => [$this->fo->id]])->assertRedirect(route('admin.access.index'));
    $user = $employee->fresh()->user;
    expect($user->role)->toBe(Role::Hod)->and($user->departments->pluck('id')->all())->toBe([$this->fo->id]);

    // Peran lain: departemen HOD dibersihkan.
    $this->put(route('admin.access.update', $employee), ['is_active' => '1', 'role' => 'staff', 'departments' => [$this->fo->id]]);
    expect($user->fresh()->departments)->toBeEmpty();

    $this->put(route('admin.access.update', $admin->employee), ['is_active' => '1', 'role' => 'staff'])->assertSessionHasErrors('role');
    expect($admin->fresh()->role)->toBe(Role::Admin);

    $this->post('/logout');
    loggedIn(userWithRole(Role::Hr));
    $this->put(route('admin.access.update', $employee), ['is_active' => '1', 'role' => 'admin'])->assertForbidden();
});

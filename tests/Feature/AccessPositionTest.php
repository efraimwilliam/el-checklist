<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;

beforeEach(function () {
    $this->department = Department::factory()->create(['name' => 'Accounting']);
    $this->purchasing = Position::factory()->for($this->department)->create(['name' => 'Purchasing']);
    $this->cost = Position::factory()->for($this->department)->create(['name' => 'Cost Control']);
    $this->employee = Employee::factory()->for($this->department)->create(['name' => 'Made Arya', 'checklist_position_id' => $this->purchasing->id]);
    loggedIn(userWithRole(Role::Admin));
});

it('shows the checklist position as a disabled field that points to Tim → Jabatan', function () {
    $html = $this->get(route('admin.access.edit', $this->employee))->assertOk()->assertSee('Tim → Jabatan')->getContent();

    expect($html)->toMatch('/<select id="position_id"[^>]*\sdisabled/')
        ->and($html)->not->toContain('name="position_id"')
        ->and($html)->toMatch('/<option value="'.$this->purchasing->id.'" selected>Purchasing<\/option>/')
        ->and($html)->toContain(route('team.positions.edit'));
});

it('saving access never changes the checklist position, even when one is submitted', function () {
    $user = User::factory()->for($this->employee)->create(['role' => Role::Staff, 'position_id' => $this->cost->id]);

    $this->put(route('admin.access.update', $this->employee), ['is_active' => '1', 'role' => 'hr', 'position_id' => $this->purchasing->id])
        ->assertRedirect(route('admin.access.index'));

    expect($user->fresh()->role)->toBe(Role::Hr)->and($user->fresh()->position_id)->toBe($this->cost->id)
        ->and($this->employee->fresh()->checklist_position_id)->toBe($this->purchasing->id);
});

it('creates an account from the access page without touching the employee position', function () {
    $this->put(route('admin.access.update', $this->employee), ['is_active' => '1', 'role' => 'staff'])->assertRedirect(route('admin.access.index'));

    expect(User::where('employee_id', $this->employee->id)->value('position_id'))->toBeNull()
        ->and($this->employee->fresh()->checklist_position_id)->toBe($this->purchasing->id);
});

it('lists the position actually used in the access table', function () {
    $other = Employee::factory()->for($this->department)->create(['name' => 'Wayan Okky', 'checklist_position_id' => null]);
    User::factory()->for($other)->create(['role' => Role::Staff, 'position_id' => $this->cost->id]);

    $this->get(route('admin.access.index'))->assertOk()->assertSee('data-position="Purchasing"', false)->assertSee('data-position="Cost Control"', false);
});

it('moves old account positions to the employee once, only where the employee has none', function () {
    $empty = Employee::factory()->for($this->department)->create(['checklist_position_id' => null]);
    User::factory()->for($empty)->create(['position_id' => $this->cost->id]);
    $kept = Employee::factory()->for($this->department)->create(['checklist_position_id' => $this->purchasing->id]);
    User::factory()->for($kept)->create(['position_id' => $this->cost->id]);

    (require database_path('migrations/2026_11_13_000001_move_user_position_to_employee.php'))->up();

    expect($empty->fresh()->checklist_position_id)->toBe($this->cost->id)->and($kept->fresh()->checklist_position_id)->toBe($this->purchasing->id);
});

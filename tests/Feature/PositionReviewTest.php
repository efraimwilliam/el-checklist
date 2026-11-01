<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'Guest Service Agent (GSA)']);
    $this->dm = Position::factory()->for($this->fo)->create(['name' => 'Duty Manager (DM)']);
    $this->ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant (RA)']);
    foreach ([$this->gsa, $this->dm, $this->ra] as $position) {
        Task::factory()->for($position)->create(['is_active' => true]);
    }
    $this->staff = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso', 'position_title' => 'GSA', 'checklist_position_id' => $this->gsa->id, 'checklist_title' => 'GSA']);
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

it('does not flag staff whose Absensi position is unchanged or was never recorded', function () {
    Employee::factory()->for($this->fo)->create(['name' => 'Sari', 'position_title' => 'DM', 'checklist_position_id' => $this->dm->id, 'checklist_title' => null]);
    loggedIn($this->hod);

    $this->get(route('team.positions.edit'))->assertOk()->assertDontSee('Perlu dicek')->assertDontSee('staff perlu dicek');
    expect($this->staff->fresh()->positionReview())->toBeNull();
});

it('flags staff whose Absensi position changed and suggests the new position without saving it', function () {
    $this->staff->update(['position_title' => 'Duty Manager']);
    loggedIn($this->hod);

    $html = $this->get(route('team.positions.edit'))->assertOk()
        ->assertSee('1 staff perlu dicek')
        ->assertSee('Perlu dicek: jabatan di Absensi berubah dari "GSA" menjadi "Duty Manager".')
        ->assertSee('Saran dari Absensi, belum disimpan')->assertSee('Jabatan checklist tetap benar')->getContent();

    expect($html)->toMatch('/<option value="'.$this->dm->id.'" selected>Duty Manager \(DM\)<\/option>/');
    expect($this->staff->fresh()->checklist_position_id)->toBe($this->gsa->id); // belum disimpan
});

it('accepting the suggestion saves the position and clears the flag', function () {
    $this->staff->update(['position_title' => 'Duty Manager']);
    loggedIn($this->hod);

    $this->put(route('team.positions.update'), ['positions' => [$this->staff->id => $this->dm->id]])->assertRedirect(route('team.positions.edit'));

    $staff = $this->staff->fresh();
    expect($staff->checklist_position_id)->toBe($this->dm->id)->and($staff->checklist_title)->toBe('Duty Manager')->and($staff->positionReview())->toBeNull();
    $this->get(route('team.positions.edit'))->assertDontSee('staff perlu dicek');
});

it('"still correct" clears the flag without changing the position, and saving the form alone does not', function () {
    $this->staff->update(['position_title' => 'Front Office Staff']); // tidak ada saran yang cocok
    loggedIn($this->hod);

    // Menyimpan formulir tanpa menyentuh baris ini: tetap ditandai.
    $this->put(route('team.positions.update'), ['positions' => [$this->staff->id => $this->gsa->id]])->assertRedirect();
    expect($this->staff->fresh()->positionReview())->toMatchArray(['type' => 'title', 'from' => 'GSA', 'to' => 'Front Office Staff']);

    $this->put(route('team.positions.update'), ['positions' => [$this->staff->id => $this->gsa->id], 'confirm' => [$this->staff->id]])->assertRedirect();

    $staff = $this->staff->fresh();
    expect($staff->checklist_position_id)->toBe($this->gsa->id)->and($staff->checklist_title)->toBe('Front Office Staff')->and($staff->positionReview())->toBeNull();
});

it('flags staff who moved department and lets the HOD pick a position in the new one', function () {
    $this->staff->update(['department_id' => $this->hk->id]);
    $hr = User::factory()->hodOf($this->hk)->create();
    loggedIn($hr);

    $this->get(route('team.positions.edit'))->assertOk()
        ->assertSee('Perlu dicek: jabatan checklist saat ini (Guest Service Agent (GSA)) bukan dari departemen pegawai ini.', false);
    expect($this->staff->fresh()->positionReview())->toBe(['type' => 'department', 'position' => 'Guest Service Agent (GSA)']);

    $this->put(route('team.positions.update'), ['positions' => [$this->staff->id => $this->ra->id]])->assertRedirect();

    $staff = $this->staff->fresh();
    expect($staff->checklist_position_id)->toBe($this->ra->id)->and($staff->positionReview())->toBeNull();
});

it('records the Absensi title when a position is set and clears it when the position is removed', function () {
    $other = Employee::factory()->for($this->fo)->create(['name' => 'Wayan', 'position_title' => 'Duty Manager', 'checklist_position_id' => null]);
    loggedIn($this->hod);

    $this->put(route('team.positions.update'), ['positions' => [$other->id => $this->dm->id, $this->staff->id => null]])->assertRedirect();

    expect($other->fresh()->checklist_title)->toBe('Duty Manager')
        ->and($this->staff->fresh()->checklist_position_id)->toBeNull()->and($this->staff->fresh()->checklist_title)->toBeNull();
});

it('describes an emptied Absensi position', function () {
    $this->staff->update(['position_title' => null]);

    expect($this->staff->fresh()->positionReview())->toBe(['type' => 'title', 'from' => 'GSA', 'to' => null]);
});

it('records the Absensi title when positions are matched from the command', function () {
    $employee = Employee::factory()->for($this->hk)->create(['position_title' => 'Room Attendant', 'checklist_position_id' => null, 'is_active' => true]);

    $this->artisan('checklist:cocokkan-jabatan', ['--terapkan' => true])->assertSuccessful();

    expect($employee->fresh()->checklist_position_id)->toBe($this->ra->id)->and($employee->fresh()->checklist_title)->toBe('Room Attendant');
});

it('backfills the recorded title for employees whose position was already set', function () {
    $done = Employee::factory()->for($this->fo)->create(['position_title' => 'Duty Manager', 'checklist_position_id' => $this->dm->id, 'checklist_title' => null]);
    $unset = Employee::factory()->for($this->fo)->create(['position_title' => 'Operator', 'checklist_position_id' => null, 'checklist_title' => null]);

    DB::statement('UPDATE employees SET checklist_title = position_title WHERE checklist_position_id IS NOT NULL AND position_title IS NOT NULL');

    expect($done->fresh()->checklist_title)->toBe('Duty Manager')->and($unset->fresh()->checklist_title)->toBeNull();
});

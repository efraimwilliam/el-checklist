<?php

use App\Checklists\PositionSuggester;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Support\Collection;

function positionsNamed(array $names, ?Department $department = null): Collection
{
    $department ??= Department::factory()->create();

    return collect($names)->map(fn (string $name) => Position::factory()->create(['department_id' => $department->id, 'name' => $name]));
}

function suggestFor(?string $title, Collection $positions): ?string
{
    return app(PositionSuggester::class)->suggest(new Employee(['position_title' => $title]), $positions)?->name;
}

it('ignores the DW / Casual prefix and reads the text inside parentheses', function () {
    $positions = positionsNamed(['Room Attendant (RA)', 'Linen & Laundry', 'Guest Service Agent (GSA)', 'Waiter / Waitress', 'Order Taker (OT)', 'Housekeeping Supervisor']);

    expect(suggestFor('DW - Housekeeping (RA)', $positions))->toBe('Room Attendant (RA)')
        ->and(suggestFor('Casual Housekeeping (RA)', $positions))->toBe('Room Attendant (RA)')
        ->and(suggestFor('DW - GSA', $positions))->toBe('Guest Service Agent (GSA)')
        ->and(suggestFor('DW Linen', $positions))->toBe('Linen & Laundry')
        ->and(suggestFor('Casual F&B Service (Waiter)', $positions))->toBe('Waiter / Waitress')
        ->and(suggestFor('DW - Housekeeping (Order Taker)', $positions))->toBe('Order Taker (OT)');
});

it('does not guess when a daily-worker title only names the department or is ambiguous', function () {
    $positions = positionsNamed(['Cook – Hot Kitchen', 'Cook DW – Breakfast', 'Cook DW – Cold Kitchen', 'Waiter / Waitress', 'Bartender']);

    expect(suggestFor('DW - F&B Service', $positions))->toBeNull()
        ->and(suggestFor('DW Cook', $positions))->toBeNull()
        ->and(suggestFor('Cook', $positions))->toBeNull()
        ->and(suggestFor('General Manager', $positions))->toBeNull();
});

it('uses the configured aliases only for positions in the employee department', function () {
    $kitchen = positionsNamed(['Chef de Partie', 'Chef de Partie – Pastry & Bakery', 'Leader Steward']);
    $other = positionsNamed(['Waiter / Waitress']);

    expect(suggestFor('CDP', $kitchen))->toBe('Chef de Partie')
        ->and(suggestFor('CDP Pastry', $kitchen))->toBe('Chef de Partie – Pastry & Bakery')
        ->and(suggestFor('Steward Leader', $kitchen))->toBe('Leader Steward')
        ->and(suggestFor('CDP', $other))->toBeNull();
});

/** Pegawai aktif di departemen ini dengan teks jabatan tertentu. */
function employeeTitled(Department $department, ?string $title, array $overrides = []): Employee
{
    return Employee::factory()->create(['department_id' => $department->id, 'position_title' => $title, 'is_active' => true, 'checklist_position_id' => null, ...$overrides]);
}

it('only simulates by default', function () {
    $department = Department::factory()->create(['name' => 'Housekeeping']);
    $ra = positionsNamed(['Room Attendant (RA)'], $department)->first();
    $employee = employeeTitled($department, 'DW - Housekeeping (RA)');

    $this->artisan('checklist:cocokkan-jabatan')->expectsOutputToContain('SIMULASI (belum disimpan): 1 pegawai dicocokkan, 0 dikosongkan')->assertSuccessful();

    expect($employee->fresh()->checklist_position_id)->toBeNull();

    $this->artisan('checklist:cocokkan-jabatan', ['--terapkan' => true])->expectsOutputToContain('Tersimpan: 1 pegawai dicocokkan')->assertSuccessful();

    expect($employee->fresh()->checklist_position_id)->toBe($ra->id);
});

it('leaves unmatched employees empty and never touches positions set by hand without --timpa', function () {
    $department = Department::factory()->create(['name' => 'Housekeeping']);
    [$ra, $spv] = positionsNamed(['Room Attendant (RA)', 'Housekeeping Supervisor'], $department);
    $unknown = employeeTitled($department, 'General Manager');
    $manual = employeeTitled($department, 'Room Attendant', ['checklist_position_id' => $spv->id]);
    $noTitle = employeeTitled($department, null);

    $this->artisan('checklist:cocokkan-jabatan', ['--terapkan' => true])->expectsOutputToContain('0 pegawai dicocokkan, 1 dikosongkan (diatur HOD), 1 tanpa teks jabatan')->assertSuccessful();

    expect($unknown->fresh()->checklist_position_id)->toBeNull()->and($noTitle->fresh()->checklist_position_id)->toBeNull()
        ->and($manual->fresh()->checklist_position_id)->toBe($spv->id);

    $this->artisan('checklist:cocokkan-jabatan', ['--terapkan' => true, '--timpa' => true])->assertSuccessful();

    expect($manual->fresh()->checklist_position_id)->toBe($ra->id);
});

it('skips inactive employees, system accounts, and employees without a department', function () {
    $department = Department::factory()->create(['name' => 'Housekeeping']);
    positionsNamed(['Room Attendant (RA)'], $department);
    $inactive = employeeTitled($department, 'Room Attendant', ['is_active' => false]);
    $system = employeeTitled($department, 'Room Attendant', ['external_id' => null]);
    $loose = Employee::factory()->create(['department_id' => null, 'position_title' => 'Room Attendant', 'is_active' => true, 'checklist_position_id' => null]);

    $this->artisan('checklist:cocokkan-jabatan', ['--terapkan' => true])->expectsOutputToContain('0 pegawai dicocokkan')->assertSuccessful();

    expect($inactive->fresh()->checklist_position_id)->toBeNull()->and($system->fresh()->checklist_position_id)->toBeNull()->and($loose->fresh()->checklist_position_id)->toBeNull();
});

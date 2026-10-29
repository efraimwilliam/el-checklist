<?php

use App\Attendance\EmployeeSyncService;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\Http;

function fakeAttendanceMasterData(array $departments, array $employees): void
{
    fakeAttendance([
        'attendance.test/api/v1/tasks/departments' => Http::response(['data' => $departments]),
        'attendance.test/api/v1/tasks/employees' => Http::response(['data' => $employees]),
    ]);
}

it('syncs departments and employees by their stable attendance IDs', function () {
    $fromWorkbook = Department::create(['code' => 'FO', 'name' => 'Front Office (workbook)']);
    $gone = Employee::factory()->create(['external_id' => 77]);
    $existing = Employee::factory()->create(['external_id' => 10, 'username' => 'lama', 'department_id' => null]);

    fakeAttendanceMasterData(
        [['id' => 5, 'code' => 'fo', 'name' => 'Front Office', 'heads' => []], ['id' => 6, 'code' => null, 'name' => 'Spa', 'heads' => []]],
        [
            ['id' => 10, 'username' => 'budi', 'previous_usernames' => ['lama'], 'name' => 'Budi', 'employee_number' => null, 'position' => 'GSA', 'department_id' => 5, 'is_active' => true, 'exit_reason' => null, 'exit_date' => null],
            ['id' => 11, 'username' => 'sari', 'previous_usernames' => [], 'name' => 'Sari', 'employee_number' => null, 'position' => null, 'department_id' => 6, 'is_active' => false, 'exit_reason' => 'resign', 'exit_date' => '2026-09-01'],
        ],
    );

    expect(app(EmployeeSyncService::class)->sync())->toBe(['departments' => 2, 'employees' => 2, 'deactivated' => 1]);

    expect($fromWorkbook->fresh())->external_id->toBe(5)->name->toBe('Front Office')
        ->and($existing->fresh())->username->toBe('budi')->department_id->toBe($fromWorkbook->id)->is_active->toBeTrue()
        ->and(Employee::where('external_id', 11)->sole())->is_active->toBeFalse()
        ->and($gone->fresh()->is_active)->toBeFalse();
});

it('reports an unreachable attendance system without changing data', function () {
    $employee = Employee::factory()->create();
    fakeAttendance(['attendance.test/*' => Http::response('down', 503)]);

    $this->artisan('attendance:sync')->assertFailed();

    expect($employee->fresh()->is_active)->toBeTrue();
});

<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;

it('refuses to create a local test staff account outside the local environment', function () {
    $this->artisan('checklist:staff-lokal staff.uji')->assertFailed();
    expect(Employee::where('username', 'staff.uji')->exists())->toBeFalse();
});

it('creates a Staff test account that logs in with its own PIN without touching Absensi', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $eng = Department::factory()->create(['name' => 'Engineering']);
    $position = Position::factory()->for($eng)->create(['name' => 'Technician']);
    Task::factory()->for($position)->create();

    $this->artisan('checklist:staff-lokal staff.uji --departemen=Engineering --pin=482913')->assertSuccessful();

    $employee = Employee::where('username', 'staff.uji')->firstOrFail();
    $user = $employee->user;
    expect($employee->external_id)->toBeNull()->and($employee->department_id)->toBe($eng->id)->and($employee->checklist_position_id)->toBe($position->id)
        ->and($user->role)->toBe(Role::Staff)->and($user->password)->not->toBe('482913');

    // Login lewat formulir yang sama; Absensi tidak dipanggil (permintaan liar ditolak oleh preventStrayRequests).
    $this->app->detectEnvironment(fn () => 'testing'); // lingkungan "local" mengaktifkan lagi pemeriksaan CSRF
    fakeAttendance([]);
    login('staff.uji', '482913')->assertRedirect();
    $this->assertAuthenticatedAs($user);

    // Salah PIN ditolak.
    auth()->logout();
    login('staff.uji', '000000')->assertSessionHasErrors();
});

it('refuses a username that belongs to an Absensi employee and a malformed PIN', function () {
    $this->app->detectEnvironment(fn () => 'local');
    Employee::factory()->create(['username' => 'budi', 'external_id' => 77]);

    $this->artisan('checklist:staff-lokal budi --pin=482913')->assertFailed();
    $this->artisan('checklist:staff-lokal baru --pin=12ab')->assertFailed();
});

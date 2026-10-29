<?php

use App\Checklists\ChecklistService;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

const ATTENDANCE_LOGIN = 'attendance.test/api/v1/tasks/auth/verify-pin';

/** Ganti semua respons palsu API (Http::fake biasa menumpuk; respons pertama yang cocok selalu menang). */
function fakeAttendance(mixed $stubs): void
{
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake($stubs);
}

/** Respons sukses API absensi untuk pegawai ini. */
function attendanceAccepts(Employee $employee, array $overrides = []): void
{
    fakeAttendance([ATTENDANCE_LOGIN => Http::response(['authenticated' => true, 'employee' => [
        'id' => $employee->external_id,
        'username' => $employee->username,
        'name' => $employee->name,
        'department_id' => $employee->department?->external_id,
        'position' => $employee->position_title,
        'is_active' => true,
        ...$overrides,
    ]])]);
}

function attendanceRefuses(string $reason, array $extra = []): void
{
    fakeAttendance([ATTENDANCE_LOGIN => Http::response(['authenticated' => false, 'reason' => $reason, ...$extra])]);
}

function login(string $username, string $pin = '482913')
{
    return test()->from('/login')->post('/login', ['username' => $username, 'pin' => $pin]);
}

/** Pengguna yang sudah login lewat alur login sebenarnya (sesi berisi waktu login). */
function loggedIn(User $user)
{
    attendanceAccepts($user->employee);
    login($user->employee->username)->assertRedirect();

    return test();
}

/** Permintaan berikutnya membaca pengguna dari database lagi, seperti permintaan HTTP sungguhan. */
function nextRequest()
{
    app('auth')->forgetGuards();

    return test();
}

function userWithRole(Role $role = Role::Staff, array $attributes = []): User
{
    return User::factory()->role($role)->create($attributes);
}

/** Checklist lewat layanan yang sama dengan aplikasi; semua tugas berstatus $status (null = dibiarkan kosong). */
function makeChecklist(Employee $employee, Position $position, ?CarbonImmutable $date = null, ?string $status = 'done', ?User $by = null): Checklist
{
    $by ??= User::factory()->role(Role::Admin)->create();
    $checklist = app(ChecklistService::class)->start($employee, $position, $date ?? CarbonImmutable::today(), $by, 'HOD Uji');
    if ($status) {
        $checklist->items()->update(['status' => $status, 'note' => $status === 'pending' ? 'alasan' : null]);
    }

    return $checklist->refresh();
}

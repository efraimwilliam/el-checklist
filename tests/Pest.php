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

const ATTENDANCE_LOGIN_PASSWORD = 'attendance.test/api/v1/tasks/auth/verify-password';

const ATTENDANCE_ACCOUNT = 'attendance.test/api/v1/tasks/account';

/**
 * Ganti semua respons palsu API (Http::fake biasa menumpuk; respons pertama yang cocok selalu menang).
 * Login mencoba password dulu: kalau stub tidak menyebutkannya, password dianggap salah (akun biasa).
 */
function fakeAttendance(mixed $stubs): void
{
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake([ATTENDANCE_LOGIN_PASSWORD => Http::response(['authenticated' => false, 'reason' => 'invalid_credentials']), ...$stubs]);
}

/** Profil pegawai seperti yang dikembalikan API absensi. */
function attendanceProfile(Employee $employee, array $overrides = []): array
{
    return [
        'id' => $employee->external_id,
        'username' => $employee->username,
        'name' => $employee->name,
        'department_id' => $employee->department?->external_id,
        'position' => $employee->position_title,
        'is_active' => true,
        ...$overrides,
    ];
}

/** Respons sukses API absensi untuk pegawai ini (lewat PIN). */
function attendanceAccepts(Employee $employee, array $overrides = []): void
{
    fakeAttendance([ATTENDANCE_LOGIN => Http::response(['authenticated' => true, 'employee' => attendanceProfile($employee, $overrides)])]);
}

/** Respons sukses API absensi untuk password dashboard pegawai ini. */
function attendanceAcceptsPassword(Employee $employee, array $overrides = []): void
{
    fakeAttendance([ATTENDANCE_LOGIN_PASSWORD => Http::response(['authenticated' => true, 'employee' => attendanceProfile($employee, $overrides)])]);
}

/** Respons API absensi untuk ubah akun (username / password). */
function fakeAccount(array $body, int $status = 200): void
{
    fakeAttendance([ATTENDANCE_ACCOUNT => Http::response($body, $status)]);
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

/** Parameter Status saja (bawaan), untuk form tugas di tes. */
function statusParameters(): array
{
    return [['name' => 'Status', 'type' => 'status']];
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

<?php

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->employee = $this->user->employee;
});

it('logs in with a valid username and PIN and sends them only from the server', function () {
    attendanceAccepts($this->employee);

    login(strtoupper($this->employee->username))->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->last_login_at)->not->toBeNull();
    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://'.ATTENDANCE_LOGIN
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer token-uji')
        && $request['username'] === $this->employee->username && $request['pin'] === '482913');
});

it('does not log in with invalid credentials and shows a generic message', function () {
    attendanceRefuses('invalid_credentials');

    login($this->employee->username, '000000')->assertRedirect('/login')->assertSessionHasErrors(['username' => 'Username atau PIN tidak sesuai.']);

    $this->assertGuest();
    expect(session()->getOldInput())->not->toHaveKey('pin');
});

it('refuses an employee who is inactive at the attendance system, with the same generic message', function () {
    attendanceRefuses('inactive');
    login($this->employee->username)->assertSessionHasErrors(['username' => 'Username atau PIN tidak sesuai.']);
    $this->assertGuest();

    // Profil lama di sini masih aktif tapi absensi bilang nonaktif.
    attendanceAccepts($this->employee, ['is_active' => false]);
    login($this->employee->username)->assertSessionHasErrors('username');
    $this->assertGuest();
    expect($this->employee->fresh()->is_active)->toBeFalse();
});

it('denies a valid employee who has no application access', function () {
    $employee = Employee::factory()->create();
    attendanceAccepts($employee);
    login($employee->username)->assertSessionHasErrors(['username' => 'Anda belum memiliki akses ke aplikasi ini. Hubungi HR / Administrator.']);
    $this->assertGuest();

    $this->user->update(['is_active' => false]); // akses dicabut
    attendanceAccepts($this->employee);
    login($this->employee->username)->assertSessionHasErrors('username');
    $this->assertGuest();
});

it('creates the local employee record for a first-time login from the attendance profile', function () {
    attendanceAccepts(new Employee(['external_id' => 9001, 'username' => 'baru', 'name' => 'Pegawai Baru', 'position_title' => 'GSA']));

    login('baru')->assertSessionHasErrors('username'); // belum diberi akses

    expect(Employee::where('external_id', 9001)->first())->username->toBe('baru')->name->toBe('Pegawai Baru');
});

it('does not authenticate when the attendance API times out, is down, or answers nonsense', function (Closure $fake) {
    $fake();

    login($this->employee->username)->assertSessionHasErrors(['username' => 'Sistem absensi sedang tidak dapat dihubungi. Coba lagi beberapa saat.']);

    $this->assertGuest();
    Http::assertSentCount(1); // tidak diulang otomatis
})->with([
    'timeout' => fn () => fakeAttendance([ATTENDANCE_LOGIN => Http::failedConnection('cURL error 28: Operation timed out')]),
    'server error' => fn () => fakeAttendance([ATTENDANCE_LOGIN => Http::response('oops', 500)]),
    'token rejected' => fn () => fakeAttendance([ATTENDANCE_LOGIN => Http::response(['message' => 'Token tidak valid.'], 401)]),
    'malformed' => fn () => fakeAttendance([ATTENDANCE_LOGIN => Http::response(['ok' => 'yes'])]),
    'success without profile' => fn () => fakeAttendance([ATTENDANCE_LOGIN => Http::response(['authenticated' => true])]),
    'unknown reason' => fn () => fakeAttendance([ATTENDANCE_LOGIN => Http::response(['authenticated' => false, 'reason' => 'apa'])]),
]);

it('does not authenticate when the API is not configured', function () {
    config(['attendance.url' => '']);
    fakeAttendance([]);

    login($this->employee->username)->assertSessionHasErrors('username');

    $this->assertGuest();
    Http::assertNothingSent();
});

it('explains a missing PIN, a locked PIN and a disabled PIN feature', function () {
    attendanceRefuses('pin_not_set');
    login($this->employee->username)->assertSessionHasErrors(['username' => 'Anda belum memiliki PIN. Minta HR/HOD membuka izin buat PIN, lalu buat PIN di aplikasi absensi. Setelah itu kembali ke aplikasi ini.']);

    attendanceRefuses('locked', ['retry_after' => 600]);
    login($this->employee->username)->assertSessionHasErrors(['username' => 'Terlalu banyak PIN salah. Coba lagi dalam 10 menit, atau reset PIN di aplikasi absensi.']);

    attendanceRefuses('pin_disabled');
    login($this->employee->username)->assertSessionHasErrors(['username' => 'Login dengan PIN sedang tidak tersedia. Hubungi HR.']);
    $this->assertGuest();
});

it('regenerates the session ID after a successful login', function () {
    $this->get('/login');
    $before = session()->getId();
    attendanceAccepts($this->employee);

    login($this->employee->username);

    expect(session()->getId())->not->toBe($before);
});

it('never writes the PIN to the logs or the session', function () {
    $log = storage_path('logs/test-pin-'.uniqid().'.log');
    config(['logging.channels.auth.driver' => 'single', 'logging.channels.auth.path' => $log, 'logging.channels.stack.channels' => ['auth']]);
    app('log')->forgetChannel('auth');
    app('log')->forgetChannel('stack');

    attendanceRefuses('invalid_credentials');
    login($this->employee->username, '918273');
    fakeAttendance([ATTENDANCE_LOGIN => Http::failedConnection('cURL error 28: Operation timed out')]);
    login($this->employee->username, '918273');
    attendanceAccepts($this->employee);
    login($this->employee->username, '918273');

    $contents = file_get_contents($log);
    unlink($log);
    expect($contents)->toContain($this->employee->username)->toContain('invalid_credentials')->toContain('timeout')->toContain('success')
        ->not->toContain('918273')
        ->and(json_encode(session()->all()))->not->toContain('918273');
});

it('locks login for a username after repeated failures without calling the API', function () {
    attendanceRefuses('invalid_credentials');
    foreach (range(1, 5) as $i) {
        login($this->employee->username, '00000'.$i);
    }

    login($this->employee->username)->assertSessionHasErrors(['username' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.']);

    Http::assertSentCount(5);
    $this->assertGuest();
});

it('does not count attendance outages as failed attempts', function () {
    fakeAttendance([ATTENDANCE_LOGIN => Http::failedConnection('down')]);
    foreach (range(1, 6) as $i) {
        login($this->employee->username);
    }

    attendanceAccepts($this->employee);
    login($this->employee->username)->assertRedirect(route('home'));
    $this->assertAuthenticated();
});

it('throttles the login form per IP', function () {
    attendanceRefuses('invalid_credentials');
    foreach (range(1, 20) as $i) {
        $this->post('/login', ['username' => "user{$i}", 'pin' => '1234']);
    }

    $this->post('/login', ['username' => 'lain', 'pin' => '1234'])->assertTooManyRequests();
});

it('sends each role to its own first page', function () {
    loggedIn($this->user)->get('/')->assertRedirect(route('checklists.today'));

    foreach ([[Role::Hod, 'dashboard'], [Role::Hr, 'dashboard'], [Role::Gm, 'dashboard'], [Role::Admin, 'dashboard']] as [$role, $route]) {
        $this->post('/logout');
        loggedIn(userWithRole($role))->get('/')->assertRedirect(route($route));
    }
});

it('invalidates the session on logout', function () {
    loggedIn($this->user);
    $sessionId = session()->getId();
    $token = session()->token();

    $this->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
    expect(session()->getId())->not->toBe($sessionId)->and(session()->token())->not->toBe($token);
    $this->get('/')->assertRedirect('/login');
});

it('ends sessions past the absolute lifetime even when active', function () {
    loggedIn($this->user);
    $this->get(route('fill.create'))->assertOk();

    $this->travel(config('session.absolute_lifetime') + 1)->minutes();

    $this->get(route('fill.create'))->assertRedirect('/login');
    $this->assertGuest();
});

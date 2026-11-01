<?php

use App\Attendance\EmployeeSyncService;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;

const PORTAL = 'https://portal.test';

/** SSO menyala dengan mode login tertentu; sistem lain (Absensi) tetap palsu. */
function ssoOn(string $mode = 'both'): void
{
    config([
        'services.sso.portal_url' => PORTAL,
        'services.sso.client_id' => 'client-uji',
        'services.sso.client_secret' => 'rahasia-uji',
        'services.sso.check_minutes' => 5,
    ]);
    app()->instance('sso.mode', $mode);
}

/** Respons palsu portal. $user = jawaban GET /api/user (status atau body), $activity = status POST /api/aktivitas. */
function fakePortal(array|int $user = [], int $activity = 200, int $token = 200): void
{
    $profile = is_array($user) ? [...['id' => 77, 'username' => 'budi', 'name' => 'Budi', 'email' => null], ...$user] : [];

    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake([
        'portal.test/oauth/token' => Http::response($token === 200 ? ['access_token' => 'token-portal'] : [], $token),
        'portal.test/api/user' => Http::response($profile, is_int($user) ? $user : 200),
        'portal.test/api/aktivitas' => Http::response(['ok' => true], $activity),
        ATTENDANCE_LOGIN_PASSWORD => Http::response(['authenticated' => false, 'reason' => 'invalid_credentials']),
    ]);
}

/** Pengguna aktif yang username-nya sama dengan akun portal. */
function ssoUser(array $employee = []): User
{
    $user = userWithRole(Role::Hod);
    $user->employee->update(['username' => 'budi', ...$employee]);

    return $user;
}

function viaPortalSession(User $user, array $overrides = [])
{
    return test()->actingAs($user)->withSession([
        'auth.logged_in_at' => now()->timestamp,
        'sso.token' => 'token-portal',
        'sso.user_id' => 77,
        'sso.checked_at' => now()->timestamp,
        'sso.reported_at' => now()->timestamp,
        ...$overrides,
    ]);
}

function ssoCallback(string $query = 'code=abc&state=state-uji')
{
    return test()->withSession(['sso.state' => 'state-uji'])->get('/sso/callback?'.$query);
}

it('is off until the portal is configured', function () {
    $this->get('/sso/login')->assertNotFound();
    $this->get('/sso/callback')->assertNotFound();
    $this->get('/login')->assertOk()->assertDontSee('Masuk lewat Portal');
});

it('sends the browser to the portal with a state value', function () {
    ssoOn();

    $response = $this->get('/sso/login');

    $response->assertRedirect();
    $url = $response->headers->get('Location');
    expect($url)->toStartWith(PORTAL.'/oauth/authorize?')
        ->and($url)->toContain('client_id=client-uji')->toContain('response_type=code')
        ->toContain(rawurlencode(route('sso.callback')));
    expect(session('sso.state'))->toHaveLength(40)->and($url)->toContain('state='.session('sso.state'));
});

it('logs in the local user whose username matches the portal account and locks the portal id', function () {
    ssoOn();
    fakePortal();
    $user = ssoUser();

    ssoCallback()->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->sso_user_id)->toBe(77)->and($user->fresh()->last_login_at)->not->toBeNull();
    expect(session('sso.token'))->toBe('token-portal')->and(session('auth.logged_in_at'))->not->toBeNull();
    Http::assertSent(fn (HttpRequest $request) => $request->url() === PORTAL.'/oauth/token'
        && $request['code'] === 'abc' && $request['client_secret'] === 'rahasia-uji');
});

it('keeps recognising the account by portal id after the username changes', function () {
    ssoOn();
    fakePortal(['id' => 77, 'username' => 'nama-baru']);
    $user = ssoUser();
    $user->forceFill(['sso_user_id' => 77])->save();

    ssoCallback()->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

it('does not give a portal account to a local user that is already tied to another portal id', function () {
    ssoOn();
    fakePortal(['id' => 99]);
    ssoUser()->forceFill(['sso_user_id' => 77])->save();

    ssoCallback()->assertRedirect(route('login'))->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('refuses a portal account without an active local user', function (string $case) {
    ssoOn();
    fakePortal();
    $user = ssoUser();

    match ($case) {
        'no user' => $user->employee->update(['username' => 'orang-lain']),
        'access revoked' => $user->update(['is_active' => false]),
        'employee inactive' => $user->employee->update(['is_active' => false]),
    };

    ssoCallback()->assertRedirect(route('login'))->assertSessionHasErrors('username');

    $this->assertGuest();
})->with(['no user', 'access revoked', 'employee inactive']);

it('refuses a callback with a wrong state, a portal error, or a code the portal rejects', function () {
    ssoOn();
    fakePortal();
    ssoUser();

    ssoCallback('code=abc&state=lain')->assertSessionHasErrors('username');
    ssoCallback('error=access_denied&state=state-uji')->assertSessionHasErrors('username');
    $this->get('/sso/callback?code=abc&state=state-uji')->assertSessionHasErrors('username'); // tanpa state di sesi

    fakePortal(token: 400);
    ssoCallback()->assertSessionHasErrors('username');
    $this->assertGuest();
});

it('tells the user when the portal says they have no access to this system', function () {
    ssoOn();
    fakePortal(user: 403);
    ssoUser();

    ssoCallback()->assertSessionHasErrors(['username' => 'Akun Anda tidak punya akses ke Checklist Kerjaan di portal.']);

    $this->assertGuest();
});

it('sends login straight to the portal when it is required and refuses username + PIN', function () {
    ssoOn('required');
    fakePortal();
    $user = ssoUser();

    $this->get('/login')->assertRedirect(route('sso.login'));
    login($user->employee->username)->assertSessionHasErrors(['username' => 'Silakan masuk lewat portal.']);
    $this->assertGuest();
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'attendance.test'));

    // Pesan gagal tetap terbaca dulu, dengan tombol portal saja (tanpa form PIN).
    $this->withSession(['errors' => new ViewErrorBag])->get('/login')->assertOk()->assertSee('Masuk lewat Portal')->assertDontSee('name="pin"', false);
});

it('offers both the portal and username + PIN in "both" mode, and PIN login still works', function () {
    ssoOn('both');
    $user = ssoUser();

    $this->get('/login')->assertOk()->assertSee('Masuk lewat Portal')->assertSee('name="pin"', false);

    attendanceAccepts($user->employee);
    login($user->employee->username)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
    // Sesi dari PIN tidak ditanyakan ke portal.
    nextRequest()->get(route('home'))->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('keeps a signed-in portal session while the portal still recognises it', function () {
    ssoOn();
    fakePortal();
    $user = ssoUser();

    viaPortalSession($user, ['sso.checked_at' => now()->subMinutes(10)->timestamp])->get(route('home'))->assertRedirect();

    $this->assertAuthenticatedAs($user);
    Http::assertSent(fn (HttpRequest $request) => $request->url() === PORTAL.'/api/user' && $request->hasHeader('Authorization', 'Bearer token-portal'));
});

it('signs the user out when the portal no longer accepts the session', function (int|array $answer) {
    ssoOn();
    fakePortal(user: $answer);
    $user = ssoUser();

    viaPortalSession($user, ['sso.checked_at' => now()->subMinutes(10)->timestamp])
        ->get(route('home'))->assertRedirect(route('login'))->assertSessionHasErrors('username');

    $this->assertGuest();
})->with([
    'token revoked' => 401,
    'access revoked' => 403,
    'someone else' => [['id' => 88]],
]);

it('answers JSON requests with 401 when the portal session has ended (autosave)', function () {
    ssoOn();
    fakePortal(user: 401);
    $user = ssoUser();

    viaPortalSession($user, ['sso.checked_at' => now()->subMinutes(10)->timestamp])
        ->getJson(route('home'))->assertUnauthorized()->assertJsonPath('message', 'Sesi portal Anda sudah berakhir. Silakan masuk lagi lewat portal.');

    $this->assertGuest();
});

it('does not sign anyone out when the portal is unreachable', function () {
    ssoOn();
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake([
        'portal.test/*' => fn () => throw new ConnectionException('mati'),
    ]);
    $user = ssoUser();

    viaPortalSession($user, ['sso.checked_at' => now()->subMinutes(10)->timestamp, 'sso.reported_at' => now()->subMinutes(2)->timestamp])
        ->get(route('home'))->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('reports activity to the portal about once a minute and ends the session when the portal says idle', function () {
    ssoOn();
    fakePortal(activity: 200);
    $user = ssoUser();

    viaPortalSession($user)->get(route('home')); // baru dilaporkan: tidak ada panggilan
    Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/api/aktivitas'));

    viaPortalSession($user, ['sso.reported_at' => now()->subMinutes(2)->timestamp])->get(route('home'));
    Http::assertSent(fn (HttpRequest $request) => $request->url() === PORTAL.'/api/aktivitas' && $request->method() === 'POST');
    $this->assertAuthenticatedAs($user);

    fakePortal(activity: 401);
    viaPortalSession($user, ['sso.reported_at' => now()->subMinutes(2)->timestamp])
        ->get(route('home'))->assertRedirect(route('login'))->assertSessionHasErrors('username');
    $this->assertGuest();
});

it('refuses a session that did not come from the portal when the portal is required', function () {
    ssoOn('required');
    $user = ssoUser();

    $this->actingAs($user)->withSession(['auth.logged_in_at' => now()->timestamp])
        ->get(route('home'))->assertRedirect(route('login'))->assertSessionHasErrors(['username' => 'Silakan masuk lewat portal.']);

    $this->assertGuest();
});

it('signs out of the portal and every system when the user logs out of a portal session', function () {
    ssoOn();
    fakePortal();
    $user = ssoUser();

    viaPortalSession($user)->post(route('logout'))->assertRedirect(PORTAL.'/keluar');

    $this->assertGuest();
});

it('only signs out locally for a session that did not come from the portal', function () {
    ssoOn();
    $user = ssoUser();

    $this->actingAs($user)->withSession(['auth.logged_in_at' => now()->timestamp])->post(route('logout'))->assertRedirect(route('login'));
});

it('signs out when the portal asks, and only goes back to addresses of the portal', function () {
    ssoOn();
    $user = ssoUser();

    $this->actingAs($user)->get('/sso/logout?next='.urlencode(PORTAL.'/keluar/lanjut'))->assertRedirect(PORTAL.'/keluar/lanjut');
    $this->assertGuest();

    $this->actingAs($user)->get('/sso/logout?next='.urlencode('https://jahat.example/'))->assertRedirect(PORTAL);
    $this->assertGuest();
});

it('shows the main dashboard link in the header only for portal sessions', function () {
    ssoOn();
    fakePortal();
    $user = ssoUser();

    viaPortalSession($user)->get(route('dashboard'))->assertOk()->assertSee(PORTAL.'/?beranda=1', false);

    $this->flushSession();
    $this->actingAs($user)->withSession(['auth.logged_in_at' => now()->timestamp])->get(route('dashboard'))->assertOk()->assertDontSee('beranda=1', false);
});

it('lets a system account listed in SSO_SYSTEM_ACCOUNTS sign in without an attendance employee record', function () {
    ssoOn();
    config(['services.sso.system_accounts' => ['admin']]);
    fakePortal(['username' => 'admin', 'name' => 'Administrator']);

    ssoCallback()->assertRedirect(route('home'));

    $user = User::where('sso_user_id', 77)->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->role)->toBe(Role::Admin)->and($user->employee->external_id)->toBeNull()
        ->and($user->employee->username)->toBe('admin')->and($user->employee->name)->toBe('Administrator');
});

it('does not let other portal accounts without an employee record in, even when a system account is allowed', function () {
    ssoOn();
    config(['services.sso.system_accounts' => ['admin']]);
    fakePortal(['username' => 'tamu']);

    ssoCallback()->assertRedirect(route('login'))->assertSessionHasErrors('username');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('creates the system account once and keeps the role an administrator changed', function () {
    ssoOn();
    config(['services.sso.system_accounts' => ['admin']]);
    fakePortal(['username' => 'admin']);

    ssoCallback();
    $user = User::where('sso_user_id', 77)->sole();
    $user->update(['role' => Role::Hr]);
    $this->post(route('logout'));

    ssoCallback()->assertRedirect(route('home'));

    expect(User::count())->toBe(1)->and(Employee::where('username', 'admin')->count())->toBe(1)
        ->and($user->fresh()->role)->toBe(Role::Hr);
});

it('keeps the system account when attendance sync runs', function () {
    ssoOn();
    config(['services.sso.system_accounts' => ['admin']]);
    fakePortal(['username' => 'admin']);
    ssoCallback();
    $system = Employee::where('username', 'admin')->sole();

    fakeAttendance([
        'attendance.test/api/v1/tasks/departments' => Http::response(['data' => []]),
        'attendance.test/api/v1/tasks/employees' => Http::response(['data' => []]),
    ]);
    app(EmployeeSyncService::class)->sync();

    expect($system->fresh()->is_active)->toBeTrue();
});

/** Portal (login SSO) dan Absensi (sinkron pegawai) dipalsukan bersamaan. */
function portalAndAttendance(string $username, array $employees, ?int $attendanceStatus = 200): void
{
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake([
        'portal.test/oauth/token' => Http::response(['access_token' => 'token-portal']),
        'portal.test/api/user' => Http::response(['id' => 77, 'username' => $username, 'name' => 'Pegawai Uji', 'email' => null]),
        'attendance.test/api/v1/tasks/departments' => Http::response(['data' => []], $attendanceStatus),
        'attendance.test/api/v1/tasks/employees' => Http::response(['data' => $employees], $attendanceStatus),
        ATTENDANCE_LOGIN_PASSWORD => Http::response(['authenticated' => false, 'reason' => 'invalid_credentials']),
    ]);
}

function absensiRow(string $username, bool $active = true): array
{
    return ['id' => 501, 'username' => $username, 'previous_usernames' => [], 'name' => 'Pegawai Uji', 'employee_number' => null, 'position' => 'Staf', 'department_id' => null, 'is_active' => $active, 'exit_reason' => null, 'exit_date' => null];
}

it('creates a Staff account for a Portal-granted employee found in fresh attendance data', function () {
    ssoOn();
    portalAndAttendance('budi', [absensiRow('budi')]);

    ssoCallback()->assertRedirect(route('home'));

    $user = User::where('sso_user_id', 77)->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->role)->toBe(Role::Staff)->and($user->employee->username)->toBe('budi')->and($user->employee->external_id)->toBe(501);
});

it('refuses a Portal-granted employee who is inactive in attendance', function () {
    ssoOn();
    portalAndAttendance('budi', [absensiRow('budi', active: false)]);

    ssoCallback()->assertRedirect(route('login'))->assertSessionHasErrors('username');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('refuses a Portal-granted username that attendance does not know', function () {
    ssoOn();
    portalAndAttendance('budi', [absensiRow('orang-lain')]);

    ssoCallback()->assertRedirect(route('login'))->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('refuses instead of guessing when attendance cannot be reached', function () {
    ssoOn();
    portalAndAttendance('budi', [], attendanceStatus: 503);

    ssoCallback()->assertRedirect(route('login'))->assertSessionHasErrors('username');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

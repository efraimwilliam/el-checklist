<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

const LOCAL_PASSWORD = 'rahasia-kuat-uji-12';

/** Akun sistem `admin` dengan password Checklist sendiri, seperti hasil checklist:admin-lokal. */
function localAdmin(string $username = 'admin'): User
{
    $employee = Employee::factory()->create(['username' => $username, 'external_id' => null, 'name' => 'Administrator']);

    return User::factory()->create(['employee_id' => $employee->id, 'role' => Role::Admin, 'password' => Hash::make(LOCAL_PASSWORD)]);
}

beforeEach(function () {
    // Login username + password tidak boleh menyentuh Absensi sama sekali.
    fakeAttendance([]);
});

it('signs in the system admin with its own password without calling attendance', function () {
    $admin = localAdmin();

    login('admin', LOCAL_PASSWORD)->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($admin);
    expect($admin->fresh()->last_login_at)->not->toBeNull();
    Http::assertNothingSent();
});

it('refuses a wrong password with the same generic message and counts it', function () {
    localAdmin();

    login('admin', 'salah-sekali-saja')->assertSessionHasErrors(['username' => 'Username atau PIN/password tidak sesuai.']);

    $this->assertGuest();
    Http::assertNothingSent();
});

it('locks the system admin after repeated wrong passwords', function () {
    localAdmin();

    foreach (range(1, 5) as $_) {
        login('admin', 'salah');
    }
    login('admin', LOCAL_PASSWORD)->assertSessionHasErrors(['username' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.']);

    $this->assertGuest();
});

it('keeps working in "login sendiri" and "keduanya" modes, but not when SSO is required', function (string $mode, bool $works) {
    config(['services.sso.portal_url' => 'https://portal.test', 'services.sso.client_id' => 'c', 'services.sso.client_secret' => 's']);
    app()->instance('sso.mode', $mode);
    localAdmin();

    $response = login('admin', LOCAL_PASSWORD);

    $works ? $response->assertRedirect(route('home')) : $response->assertSessionHasErrors('username');
    $works ? $this->assertAuthenticatedAs(User::first()) : $this->assertGuest();
})->with([
    'login sendiri' => ['local', true],
    'keduanya' => ['both', true],
    'wajib sso' => ['required', false],
]);

it('does not store the password in the activity log', function () {
    localAdmin();
    login('admin', LOCAL_PASSWORD);

    $user = User::first();
    $user->forceFill(['role' => Role::Hr])->save();

    expect(AuditLog::where('changes', 'like', '%password%')->count())->toBe(0);
    expect(json_encode($user->fresh()->toArray()))->not->toContain(Hash::make(LOCAL_PASSWORD))->not->toContain('"password"');
});

it('does not let the command take over a real attendance employee', function () {
    $employee = Employee::factory()->create(['username' => 'budi']);
    $this->artisan('checklist:admin-lokal', ['username' => 'budi'])
        ->expectsOutputToContain('pegawai di Sistem Absensi')
        ->assertFailed();

    expect($employee->fresh()->user)->toBeNull();
});

it('creates the system admin from the command with a confirmed password of at least 12 characters', function () {
    $this->artisan('checklist:admin-lokal', ['username' => 'admin'])
        ->expectsQuestion('Password baru (minimal 12 karakter)', LOCAL_PASSWORD)
        ->expectsQuestion('Ulangi password', LOCAL_PASSWORD)
        ->assertSuccessful();

    $user = User::sole();
    expect($user->role)->toBe(Role::Admin)->and($user->employee->external_id)->toBeNull()
        ->and(Hash::check(LOCAL_PASSWORD, $user->password))->toBeTrue();

    $this->artisan('checklist:admin-lokal', ['username' => 'admin'])
        ->expectsQuestion('Password baru (minimal 12 karakter)', 'pendek')
        ->assertFailed();
});

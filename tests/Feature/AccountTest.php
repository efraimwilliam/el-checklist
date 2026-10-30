<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->employee = $this->user->employee;
    $this->employee->forceFill(['username' => 'budi'])->save();
});

function accountUpdated(string $username): array
{
    return ['ok' => true, 'employee' => ['id' => 1, 'username' => $username, 'name' => 'Pegawai', 'department_id' => null, 'position' => null, 'is_active' => true]];
}

it('shows the profile form with the current username', function () {
    loggedIn($this->user);

    $this->get(route('profile.edit'))->assertOk()->assertSee('value="budi"', false)->assertSee('Simpan ke Sistem Absensi');
});

it('saves a username change to Absensi and updates the local copy', function () {
    loggedIn($this->user);
    fakeAccount(accountUpdated('budi.baru'));

    $this->put(route('profile.update'), ['username' => 'budi.baru', 'secret' => '482913'])
        ->assertRedirect(route('profile.edit'))->assertSessionHas('status');

    expect($this->employee->fresh()->username)->toBe('budi.baru');
    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://'.ATTENDANCE_ACCOUNT
        && $request->method() === 'PATCH'
        && $request['actor_username'] === 'budi'
        && $request['actor_secret'] === '482913'
        && $request['username'] === 'budi.baru'
        && ! isset($request['password']));
});

it('shows the Absensi refusal next to the PIN field and keeps nothing stored locally', function () {
    loggedIn($this->user);
    fakeAccount(['ok' => false, 'reason' => 'invalid_credentials']);

    $this->put(route('profile.update'), ['username' => 'budi.baru', 'secret' => '000000'])
        ->assertSessionHasErrors(['secret' => 'PIN atau password tidak sesuai.']);

    expect($this->employee->fresh()->username)->toBe('budi');
});

it('shows a username problem from Absensi next to the username field', function () {
    loggedIn($this->user);
    fakeAccount(['ok' => false, 'reason' => 'username_problem', 'message' => 'Username ":u" sudah dipakai. Pilih username lain.']);

    $this->put(route('profile.update'), ['username' => 'ani', 'secret' => '482913'])
        ->assertSessionHasErrors(['username']);
});

it('rejects an invalid username before calling Absensi', function () {
    loggedIn($this->user);
    fakeAccount(['ok' => true]);

    $this->put(route('profile.update'), ['username' => 'a b', 'secret' => '482913'])->assertSessionHasErrors('username');
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'account'));
});

it('sends a new dashboard password only for dashboard accounts', function () {
    $hod = User::factory()->role(Role::Hod)->create();
    loggedIn($hod);
    fakeAccount(['ok' => true, 'employee' => accountUpdated('budi')['employee']]);

    $this->put(route('profile.update'), ['username' => 'budi', 'password' => 'sandi-baru-1', 'password_confirmation' => 'sandi-baru-1', 'secret' => 'sandi-lama-1'])
        ->assertRedirect(route('profile.edit'));
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'account') && $request['password'] === 'sandi-baru-1' && $request['actor_secret'] === 'sandi-lama-1');

    $this->post('/logout');
    loggedIn($this->user);
    $this->put(route('profile.update'), ['username' => 'budi', 'password' => 'sandi-baru-1', 'password_confirmation' => 'sandi-baru-1', 'secret' => '482913'])
        ->assertSessionHasErrors('password');
});

it('lets HR open and change an employee account from the team page, but not HOD', function () {
    $hr = User::factory()->role(Role::Hr)->create();
    loggedIn($hr);
    $this->get(route('team.account.edit', $this->employee))->assertOk()->assertSee('Akun pegawai');

    $this->post('/logout');
    $hod = User::factory()->role(Role::Hod)->create();
    loggedIn($hod);
    $this->get(route('team.account.edit', $this->employee))->assertForbidden();
});

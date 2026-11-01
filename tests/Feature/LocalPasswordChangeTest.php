<?php

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

const OLD_LOCAL = 'password-lama-uji';
const NEW_LOCAL = 'password-baru-uji';

/** Akun sistem dengan password Checklist, seperti hasil checklist:admin-lokal. */
function localAccount(): User
{
    $employee = Employee::factory()->create(['username' => 'admin', 'external_id' => null, 'name' => 'Administrator']);

    return User::factory()->create(['employee_id' => $employee->id, 'role' => Role::Admin, 'password' => Hash::make(OLD_LOCAL)]);
}

/** Masuk dengan password lokal (login form biasa), tanpa menyentuh Absensi. */
function signedInLocal(User $user)
{
    fakeAttendance([]);
    login('admin', OLD_LOCAL)->assertRedirect(route('home'));

    return test();
}

it('shows the short password form for a system account, without username or Absensi fields', function () {
    $admin = localAccount();
    signedInLocal($admin)->get(route('profile.edit'))->assertOk()
        ->assertSee('Password saat ini')->assertSee('Ulangi password baru')
        ->assertDontSee('name="username"', false)->assertDontSee('name="secret"', false);
});

it('changes the local password without calling Absensi and keeps the session signed in', function () {
    $admin = localAccount();
    signedInLocal($admin)->put(route('profile.update'), [
        'current_password' => OLD_LOCAL, 'password' => NEW_LOCAL, 'password_confirmation' => NEW_LOCAL,
    ])->assertRedirect(route('profile.edit'));

    expect(Hash::check(NEW_LOCAL, $admin->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($admin);
    Http::assertNothingSent();
});

it('refuses a wrong current password and keeps the old one', function () {
    $admin = localAccount();
    signedInLocal($admin)->put(route('profile.update'), [
        'current_password' => 'salah-sekali', 'password' => NEW_LOCAL, 'password_confirmation' => NEW_LOCAL,
    ])->assertSessionHasErrors(['current_password' => 'Password saat ini salah.']);

    expect(Hash::check(OLD_LOCAL, $admin->fresh()->password))->toBeTrue();
});

it('refuses a new password that matches the old one or does not match its confirmation', function () {
    $admin = localAccount();
    $page = signedInLocal($admin);

    $page->put(route('profile.update'), ['current_password' => OLD_LOCAL, 'password' => OLD_LOCAL, 'password_confirmation' => OLD_LOCAL])
        ->assertSessionHasErrors('password');
    $page->put(route('profile.update'), ['current_password' => OLD_LOCAL, 'password' => NEW_LOCAL, 'password_confirmation' => 'lain-lagi'])
        ->assertSessionHasErrors('password');
});

it('locks the change after repeated wrong current passwords', function () {
    $admin = localAccount();
    $page = signedInLocal($admin);

    foreach (range(1, 5) as $_) {
        $page->put(route('profile.update'), ['current_password' => 'salah', 'password' => NEW_LOCAL, 'password_confirmation' => NEW_LOCAL]);
    }
    $page->put(route('profile.update'), ['current_password' => OLD_LOCAL, 'password' => NEW_LOCAL, 'password_confirmation' => NEW_LOCAL])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check(OLD_LOCAL, $admin->fresh()->password))->toBeTrue();
});

it('signs the new password in on the next login', function () {
    $admin = localAccount();
    signedInLocal($admin)->put(route('profile.update'), [
        'current_password' => OLD_LOCAL, 'password' => NEW_LOCAL, 'password_confirmation' => NEW_LOCAL,
    ]);
    nextRequest();
    $this->post('/logout');

    login('admin', NEW_LOCAL)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($admin);
});

<?php

use App\Enums\Role;

it('names the task menu entries Atur tugas pegawai, Kamus daftar tugas, and Jabatan dan tugas', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->get(route('team.index'))->assertOk()
        ->assertSee('Atur tugas pegawai')->assertSee('Kamus daftar tugas')->assertSee('Jabatan dan tugas')
        ->assertDontSee('Daftar checklist')->assertDontSee('Kelola tugas');
    $this->get(route('team.catalog'))->assertOk()->assertSee('Kamus daftar tugas');
    $this->get(route('admin.tasks.index'))->assertOk()->assertSee('Jabatan dan tugas');
});

it('shows the renamed menu entries in English', function () {
    loggedIn(userWithRole(Role::Admin));
    $this->get(route('locale.update', 'en'))->assertRedirect();

    $this->get(route('team.index'))->assertOk()->assertSee('Manage employee tasks')->assertSee('Task catalog')->assertSee('Positions &amp; tasks', false);
});

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

it('puts Riwayat checklist inside the Checklist menu, with no separate Riwayat menu', function () {
    foreach ([Role::Admin, Role::Hod, Role::Staff] as $role) {
        loggedIn(userWithRole($role));
        $html = $this->get(route('checklists.index'))->assertOk()->assertSee('Riwayat checklist')->getContent();
        // Entri menu "Riwayat" yang berdiri sendiri tidak ada lagi; "Riwayat checklist" hanya muncul di dalam menu Checklist (komputer + panel HP).
        expect($html)->not->toMatch('~>\s*Riwayat\s*</a>~');
        $this->post(route('logout'));
        app('auth')->forgetGuards();
    }

    // GM tidak mengisi checklist, jadi tidak punya menu Checklist: Riwayat checklist berdiri sendiri.
    loggedIn(userWithRole(Role::Gm));
    $this->get(route('checklists.index'))->assertOk()->assertSee('Riwayat checklist')->assertDontSee('Atur checklist');
});

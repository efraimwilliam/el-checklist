<?php

use App\Enums\Role;

it('merges the task pages into one Tugas & jabatan page, with Kamus daftar tugas as its own menu', function () {
    loggedIn(userWithRole(Role::Admin));

    // Satu entri menu, satu judul, dua tab (Pegawai, Jabatan); tab yang dibuka menyala. Kamus daftar tugas tetap menu sendiri (bukan tab).
    $html = $this->get(route('team.index'))->assertOk()
        ->assertSee('Tugas &amp; jabatan', false)->assertSee('Pegawai')->assertSee('Atur jabatan sekaligus')->assertSee('Kamus daftar tugas')
        ->assertDontSee('Atur tugas pegawai')->assertDontSee('Jabatan pegawai')->assertDontSee('Daftar checklist')->assertDontSee('Kelola tugas')->getContent();
    expect($html)->toMatch('~aria-current="page"[^>]*>Pegawai</a>~');
    $catalog = $this->get(route('team.catalog'))->assertOk()->assertSee('Kamus daftar tugas')->assertDontSee('Bagian tugas dan jabatan')->getContent();
    expect($catalog)->toMatch('~aria-current="page"[^>]*>Kamus daftar tugas</a>~');
    $this->get(route('admin.tasks.index'))->assertOk()->assertSee('Jabatan dan tugasnya');
    // Atur jabatan sekaligus bukan tab sendiri, tetapi tab Pegawai tetap menyala.
    expect($this->get(route('team.positions.edit'))->assertOk()->assertSee('Atur jabatan sekaligus')->getContent())->toMatch('~aria-current="page"[^>]*>Pegawai</a>~');
});

it('shows the merged menu entry in English', function () {
    loggedIn(userWithRole(Role::Admin));
    $this->get(route('locale.update', 'en'))->assertRedirect();

    $this->get(route('team.index'))->assertOk()->assertSee('Tasks &amp; positions', false)->assertSee('Employee')->assertSee('Set positions in bulk');
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

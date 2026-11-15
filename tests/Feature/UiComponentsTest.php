<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('renders the shared ui components', function () {
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag(['room' => 'Kamar tidak ada.'])));
    $paginator = new Illuminate\Pagination\LengthAwarePaginator(range(1, 10), 45, 10, 2, ['path' => '/kamar']);

    $html = Blade::render(<<<'BLADE'
        <x-ui.page-header title="Data kamar" subtitle="Sub" back="/pengaturan" back-label="Pengaturan"><x-slot:actions><x-ui.button>Simpan</x-ui.button></x-slot:actions></x-ui.page-header>
        <x-ui.button variant="secondary" size="sm" href="/x">Tautan</x-ui.button>
        <x-ui.icon-button label="Hapus" tone="danger"><svg></svg></x-ui.icon-button>
        <x-ui.badge tone="done">Done</x-ui.badge>
        <x-ui.count :n="0" /><x-ui.count :n="150" />
        <x-ui.field name="room" label="Nomor kamar" hint="Contoh 305"><input id="room" class="input"></x-ui.field>
        <x-ui.dialog size="sm" title="Judul"><p>Isi</p><x-slot:footer><x-ui.button>OK</x-ui.button></x-slot:footer></x-ui.dialog>
        <x-ui.pagination :paginator="$paginator" unit="kamar" />
        <x-ui.empty-state title="Belum ada kamar" text="Tambah kamar dulu."><x-ui.button>Tambah</x-ui.button></x-ui.empty-state>
        <x-ui.alert tone="danger">Gagal</x-ui.alert>
        BLADE, ['paginator' => $paginator]);

    expect($html)
        ->toContain('class="text-page">Data kamar</h1>')
        ->toContain('href="/pengaturan"')
        ->toContain('class="btn-primary" type="submit"')
        ->toContain('class="btn-secondary btn-sm"')
        ->toContain('aria-label="Hapus"')
        ->toContain('class="pill-done"')
        ->toContain('99+')
        ->toContain('data-field-error>Kamar tidak ada.')
        ->not->toContain('Contoh 305') // error menggantikan keterangan
        ->toContain('<form method="dialog">')
        ->toContain('Halaman 2 / 5 · 45 kamar')
        ->toContain('rel="prev"')
        ->toContain('Belum ada kamar')
        ->toContain('role="alert"');
});

it('shows the success message as a toast and asks before signing out', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->withSession(['status' => 'Tersimpan.'])->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-flash="Tersimpan."', false)
        ->assertSee('data-toasts', false)
        ->assertSee('data-confirm-title="Keluar dari aplikasi?"', false);
});

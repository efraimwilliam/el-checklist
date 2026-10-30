<?php

use App\Enums\Role;
use App\Health\SystemHealth;
use Illuminate\Support\Facades\Cache;

it('shows the system health section on the settings page to Administrator only', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('Jadwal otomatis & pembuatan')->assertSee('Penyimpanan')->assertSee('Aplikasi')->assertSee('Server');

    $this->post('/logout');
    loggedIn(userWithRole(Role::Hr));
    $this->get(route('admin.settings.edit'))->assertOk()->assertDontSee('id="kesehatan"', false);
});

it('flags a scheduler that has not run recently and shows it as running when it has', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->get(route('admin.settings.edit'))->assertSee('Belum tercatat');

    Cache::forever(SystemHealth::SCHEDULER_CACHE, now()->toIso8601String());
    $this->get(route('admin.settings.edit'))->assertSee('Berjalan');

    Cache::forever(SystemHealth::SCHEDULER_CACHE, now()->subHour()->toIso8601String());
    $this->get(route('admin.settings.edit'))->assertSee('Berhenti');
});

it('reports the last photo cleanup', function () {
    loggedIn(userWithRole(Role::Admin));
    Cache::forever(SystemHealth::PHOTO_PRUNE_CACHE, ['at' => now()->toIso8601String(), 'deleted' => 7]);

    $this->get(route('admin.settings.edit'))->assertSee('7 foto dihapus pada proses terakhir');
});

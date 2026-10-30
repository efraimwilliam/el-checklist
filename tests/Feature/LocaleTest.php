<?php

use App\Enums\Role;

it('switches the interface between Indonesian and English and remembers the choice', function () {
    loggedIn(userWithRole(Role::Hod));

    $this->get(route('locale.update', 'en'))->assertRedirect();
    $this->get(route('fill.create'))->assertOk()->assertSee('Fill checklist')->assertSee('Log out')->assertSee('Task list');

    $this->get(route('locale.update', 'id'))->assertRedirect();
    $this->get(route('fill.create'))->assertOk()->assertSee('Isi checklist')->assertSee('Keluar');
});

it('refuses unknown languages', function () {
    $this->get('/bahasa/fr')->assertNotFound();
});

it('translates table headers and labels on the team and access pages', function () {
    loggedIn(userWithRole(Role::Admin));
    $this->get(route('locale.update', 'en'))->assertRedirect();

    $this->get(route('team.catalog'))->assertOk()->assertSee('Position')->assertSee('Department')->assertDontSee('>Jabatan<', false);
    $this->get(route('admin.access.index'))->assertOk()->assertSee('Employee')->assertSee('Role')->assertDontSee('>Pegawai<', false);
});

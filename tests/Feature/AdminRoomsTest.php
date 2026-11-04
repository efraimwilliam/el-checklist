<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\User;

beforeEach(function () {
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    Room::create(['number' => '101', 'floor' => '1', 'type' => 'Deluxe', 'sort' => 1]);
    Room::create(['number' => '205', 'floor' => '2', 'building' => 'Gedung B', 'type' => 'Suite', 'sort' => 2]);
    Room::create(['number' => '206', 'floor' => '2', 'is_active' => false, 'sort' => 3]);
    $this->hr = User::factory()->role(Role::Hr)->create();
});

it('shows summary cards, a tidy room list and the edit and add dialogs', function () {
    loggedIn($this->hr);
    $this->get(route('admin.rooms.index'))->assertOk()
        ->assertSee('Data kamar')->assertSee('+ Tambah kamar')->assertSee('Impor Excel')
        ->assertSee('101')->assertSee('205')->assertSee('Gedung B')->assertSee('Suite')->assertSee('Nonaktif')
        ->assertSee('id="add-room"', false)->assertSee('id="edit-room"', false)->assertSee('data-edit-room', false)
        ->assertSee('1 nonaktif');
});

it('filters rooms by search, floor and status and explains an empty result', function () {
    loggedIn($this->hr);
    $this->get(route('admin.rooms.index', ['floor' => '2']))->assertOk()->assertSee('205')->assertDontSee('101');
    $this->get(route('admin.rooms.index', ['status' => 'nonaktif']))->assertOk()->assertSee('206')->assertDontSee('205');
    $this->get(route('admin.rooms.index', ['q' => '10']))->assertOk()->assertSee('101')->assertDontSee('205');
    $this->get(route('admin.rooms.index', ['q' => '999']))->assertOk()->assertSee('Tidak ada kamar yang cocok.')->assertSee('Hapus filter');
});

it('lists categories on their own tab with the add and edit dialogs', function () {
    IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->eng->id, 'sort' => 90]);

    loggedIn($this->hr);
    $this->get(route('admin.rooms.index', ['tab' => 'kategori']))->assertOk()
        ->assertSee('+ Tambah kategori')->assertSee('AC uji')->assertSee('Engineering')->assertSee('data-edit-category', false)
        ->assertDontSee('Cari nomor kamar');
});

it('keeps saving, deactivating and validating rooms and categories from the dialogs', function () {
    loggedIn($this->hr);
    $room = Room::where('number', '101')->first();

    $this->put(route('admin.rooms.update', $room), ['_form' => 'edit-room', '_id' => $room->id, 'number' => '101', 'floor' => '1', 'type' => 'Suite', 'is_active' => '0'])->assertRedirect();
    expect($room->refresh()->type)->toBe('Suite')->and($room->is_active)->toBeFalse();

    $this->from(route('admin.rooms.index'))->post(route('admin.rooms.store'), ['_form' => 'add-room', 'number' => '205'])->assertSessionHasErrors('number');
    $this->post(route('admin.rooms.store'), ['_form' => 'add-room', 'number' => '301', 'floor' => '3'])->assertRedirect();
    expect(Room::where('number', '301')->exists())->toBeTrue();

    $category = IssueCategory::create(['name' => 'Kategori uji', 'sort' => 91]);
    $this->put(route('admin.categories.update', $category), ['_form' => 'edit-category', 'name' => 'Kategori uji 2', 'department_id' => $this->eng->id, 'is_active' => '1'])->assertRedirect();
    expect($category->refresh()->name)->toBe('Kategori uji 2')->and($category->department_id)->toBe($this->eng->id);
});

it('offers Kamar, Kategori kendala and Denah as a submenu of Data kamar in the Tugas menu instead of page tabs', function () {
    loggedIn($this->hr);
    $html = $this->get(route('admin.rooms.index'))->assertOk()->getContent();

    expect($html)->toContain('group/sub')
        ->toContain(route('admin.rooms.index', ['tab' => 'kamar']))->toContain(str_replace('&', '&amp;', route('admin.rooms.index', ['tab' => 'kategori'])))->toContain(route('admin.rooms.index', ['tab' => 'denah']))
        ->not->toContain('aria-label="Bagian data kamar"');

    // Judul halaman mengikuti bagian yang dibuka.
    $this->get(route('admin.rooms.index', ['tab' => 'kategori']))->assertOk()->assertSee('Kategori masalah dan departemen penanggung jawab bawaannya.')->assertDontSee('Cari nomor kamar');
    $this->get(route('admin.rooms.index', ['tab' => 'denah']))->assertOk()->assertSee('Gambar denah per lantai atau kawasan');
});

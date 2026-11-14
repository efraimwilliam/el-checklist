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

it('deletes a room that has no issue history, together with its floor plan box, and refuses one that does', function () {
    $free = Room::where('number', '101')->firstOrFail();
    $used = Room::where('number', '205')->firstOrFail();
    $category = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->eng->id, 'sort' => 99]);
    \App\Models\RoomIssue::create([
        'room_id' => $used->id, 'category_id' => $category->id, 'source' => 'tamu', 'description' => 'AC bocor', 'priority' => 'biasa',
        'reported_by' => $this->hr->id, 'assigned_department_id' => $this->eng->id, 'status' => 'baru',
    ]);
    $plan = \App\Models\FloorPlan::create(['name' => 'Lantai 1', 'floor' => '1', 'image_path' => 'floor-plans/x.png', 'width' => 100, 'height' => 100, 'sort' => 1]);
    \App\Models\FloorPlanArea::create(['floor_plan_id' => $plan->id, 'room_id' => $free->id, 'x' => 10, 'y' => 10, 'w' => 10, 'h' => 10]);

    loggedIn($this->hr);
    // Ikon hapus aktif untuk kamar tanpa riwayat; kamar dengan kendala tampil terkunci dengan penjelasan.
    $html = $this->get(route('admin.rooms.index'))->assertOk()->getContent();
    expect($html)->toContain('action="'.route('admin.rooms.destroy', $free).'"')->not->toContain('action="'.route('admin.rooms.destroy', $used).'"')
        ->toContain('Punya 1 kendala dalam riwayat');

    $this->delete(route('admin.rooms.destroy', $free))->assertRedirect()->assertSessionHas('status', 'Kamar 101 dihapus.');
    expect(Room::whereKey($free->id)->exists())->toBeFalse()->and(\App\Models\FloorPlanArea::where('room_id', $free->id)->count())->toBe(0)
        ->and(\App\Models\FloorPlan::whereKey($plan->id)->exists())->toBeTrue();

    $this->delete(route('admin.rooms.destroy', $used))->assertRedirect()->assertSessionHasErrors('room');
    expect(Room::whereKey($used->id)->exists())->toBeTrue()->and($used->issues()->count())->toBe(1);
});

it('lets only Administrator and HR delete rooms', function () {
    $room = Room::where('number', '101')->firstOrFail();
    loggedIn(User::factory()->role(Role::Hod)->create());
    $this->delete(route('admin.rooms.destroy', $room))->assertForbidden();
    expect(Room::whereKey($room->id)->exists())->toBeTrue();
});

<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\FloorPlan;
use App\Models\FloorPlanArea;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $this->r101 = Room::create(['number' => '101', 'floor' => '1', 'sort' => 1]);
    $this->r102 = Room::create(['number' => '102', 'floor' => '1', 'sort' => 2]);
    $this->r201 = Room::create(['number' => '201', 'floor' => '2', 'sort' => 3]);
    $this->hr = User::factory()->role(Role::Hr)->create();
});

function makePlan(string $name = 'Lantai 1', ?string $floor = '1'): FloorPlan
{
    $stored = \App\Rooms\FloorPlanImage::store(UploadedFile::fake()->image('denah.png', 1200, 800));

    return FloorPlan::create(['name' => $name, 'floor' => $floor, 'image_path' => $stored['path'], 'width' => $stored['width'], 'height' => $stored['height']]);
}

it('stores an uploaded plan image as a private PNG with its size and opens the editor', function () {
    loggedIn($this->hr);
    $response = $this->postJson(route('admin.plans.store'), ['name' => 'Lantai 1', 'floor' => '1', 'image' => UploadedFile::fake()->image('a.png', 4000, 2000)])->assertCreated();

    $plan = FloorPlan::firstOrFail();
    expect($response->json('redirect'))->toBe(route('admin.plans.edit', $plan))
        ->and($plan->width)->toBe(3000)->and($plan->height)->toBe(1500)->and($plan->image_path)->toEndWith('.png');
    Storage::disk('local')->assertExists($plan->image_path);
    $this->get(route('admin.plans.edit', $plan))->assertOk()->assertSee('Lantai 1')->assertSee('data-plan-editor', false)->assertSee('101')->assertSee('201');
});

it('refuses a plan that is not a real image or has no name', function () {
    loggedIn($this->hr);
    $this->postJson(route('admin.plans.store'), ['name' => 'X', 'image' => UploadedFile::fake()->createWithContent('denah.png', 'bukan gambar')])->assertStatus(422);
    $this->postJson(route('admin.plans.store'), ['name' => '', 'image' => UploadedFile::fake()->image('a.png')])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson(route('admin.plans.store'), ['name' => 'PDF', 'image' => UploadedFile::fake()->create('denah.pdf', 100, 'application/pdf')])->assertStatus(422)->assertJsonValidationErrors('image');
    expect(FloorPlan::count())->toBe(0);
});

it('saves room areas in percent, replaces the list and keeps one area per room across plans', function () {
    $plan = makePlan();
    $other = makePlan('Lantai 2', '2');
    loggedIn($this->hr);

    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [
        ['room_id' => $this->r101->id, 'x' => 10, 'y' => 20, 'w' => 15, 'h' => 12],
        ['room_id' => $this->r102->id, 'x' => 30, 'y' => 20, 'w' => 15, 'h' => 12],
    ]])->assertOk()->assertJson(['ok' => true, 'count' => 2]);
    expect(FloorPlanArea::count())->toBe(2)->and(FloorPlanArea::where('room_id', $this->r101->id)->first()->w)->toBe(15.0);

    // Daftar baru menggantikan yang lama: kamar yang tidak ada dilepas.
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [['room_id' => $this->r101->id, 'x' => 12, 'y' => 20, 'w' => 15, 'h' => 12]]])->assertOk();
    expect(FloorPlanArea::pluck('room_id')->all())->toBe([$this->r101->id])->and(FloorPlanArea::first()->x)->toBe(12.0);

    // Kamar yang sudah ditandai di denah lain ditolak; batas dan duplikat divalidasi.
    $this->putJson(route('admin.plans.areas', $other), ['areas' => [['room_id' => $this->r101->id, 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5]]])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '101'));
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [['room_id' => $this->r201->id, 'x' => 120, 'y' => 1, 'w' => 5, 'h' => 5]]])->assertStatus(422);
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [['room_id' => $this->r201->id, 'x' => 1, 'y' => 1, 'w' => 0.1, 'h' => 5]]])->assertStatus(422);
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [
        ['room_id' => $this->r201->id, 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5], ['room_id' => $this->r201->id, 'x' => 9, 'y' => 1, 'w' => 5, 'h' => 5],
    ]])->assertStatus(422);

    // Kotak yang melewati tepi gambar dipotong ke dalam gambar.
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [['room_id' => $this->r201->id, 'x' => 90, 'y' => 90, 'w' => 30, 'h' => 30]]])->assertOk();
    $area = FloorPlanArea::where('room_id', $this->r201->id)->first();
    expect($area->x + $area->w)->toBeLessThanOrEqual(100.001)->and($area->y + $area->h)->toBeLessThanOrEqual(100.001);

    $this->r201->update(['is_active' => false]);
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [['room_id' => $this->r201->id, 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5]]])->assertStatus(422);
});

it('keeps plan management to Admin and HR and serves the image only to signed-in issue users', function () {
    $plan = makePlan();

    loggedIn(User::factory()->hodOf($this->eng)->create());
    $this->get(route('admin.plans.edit', $plan))->assertForbidden();
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => []])->assertForbidden();
    $this->postJson(route('admin.plans.store'), ['name' => 'x', 'image' => UploadedFile::fake()->image('a.png')])->assertForbidden();
    $this->get(route('denah.image', $plan))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->post('/logout');

    $this->get(route('denah.image', $plan))->assertRedirect();
});

it('renames, deactivates and deletes a plan together with its areas and image', function () {
    $plan = makePlan();
    FloorPlanArea::create(['floor_plan_id' => $plan->id, 'room_id' => $this->r101->id, 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5]);
    $path = $plan->image_path;

    loggedIn($this->hr);
    $this->put(route('admin.plans.update', $plan), ['name' => 'Gedung A', 'floor' => '1', 'sort' => 3, 'is_active' => '0'])->assertRedirect();
    expect($plan->refresh()->name)->toBe('Gedung A')->and($plan->is_active)->toBeFalse();

    $this->delete(route('admin.plans.destroy', $plan))->assertRedirect(route('admin.rooms.index', ['tab' => 'denah']));
    expect(FloorPlan::count())->toBe(0)->and(FloorPlanArea::count())->toBe(0)->and(Room::count())->toBe(3);
    Storage::disk('local')->assertMissing($path);
});

it('lists plans with progress on the Denah tab of the room data page', function () {
    $plan = makePlan('Lantai 1', '1');
    FloorPlanArea::create(['floor_plan_id' => $plan->id, 'room_id' => $this->r101->id, 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5]);

    loggedIn($this->hr);
    $this->get(route('admin.rooms.index', ['tab' => 'denah']))->assertOk()->assertSee('Lantai 1')->assertSee('1 dari 2 kamar sudah ditandai')->assertSee('+ Unggah denah')->assertSee('id="upload-plan"', false);
});

it('shows the real plan in the room map with colored, clickable areas and still offers the list view', function () {
    $plan = makePlan();
    FloorPlanArea::create(['floor_plan_id' => $plan->id, 'room_id' => $this->r101->id, 'x' => 10, 'y' => 20, 'w' => 15, 'h' => 12]);
    FloorPlanArea::create(['floor_plan_id' => $plan->id, 'room_id' => $this->r102->id, 'x' => 30, 'y' => 20, 'w' => 15, 'h' => 12]);
    $category = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->eng->id, 'sort' => 90]);
    foreach (range(1, 3) as $n) {
        RoomIssue::create(['room_id' => $this->r101->id, 'category_id' => $category->id, 'source' => 'tamu', 'description' => "Kendala {$n}", 'priority' => 'biasa', 'reported_by' => $this->hr->id, 'assigned_department_id' => $this->eng->id, 'status' => 'baru']);
    }
    RoomIssue::create(['room_id' => $this->r201->id, 'category_id' => $category->id, 'source' => 'tamu', 'description' => 'Di luar denah', 'priority' => 'biasa', 'reported_by' => $this->hr->id, 'assigned_department_id' => $this->eng->id, 'status' => 'baru']);
    $range = ['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()];

    loggedIn($this->hr);
    fakeSchedules([]);
    $html = $this->get(route('laporan.rooms', $range))->assertOk()
        ->assertSee(route('denah.image', $plan), false)->assertSee('data-plan-map', false)
        ->assertSee('/laporan/kamar/'.$this->r101->id.'?', false)->assertSee('left: 10%', false)->assertSee('Belum ada di denah')->getContent();
    expect($html)->toContain('3×')->and($html)->toContain('201');

    $this->get(route('laporan.rooms', $range + ['tampilan' => 'daftar']))->assertOk()->assertSee('Peta per lantai')->assertDontSee('data-plan-map', false);
});

it('shows a plan in the room map even before any room is marked, with a hint to mark it', function () {
    $plan = makePlan('Kawasan', null);
    loggedIn($this->hr);
    fakeSchedules([]);
    $this->get(route('laporan.rooms'))->assertOk()->assertSee('data-plan-map', false)->assertSee(route('denah.image', $plan), false)
        ->assertSee('Belum ada kamar atau gedung yang ditandai di denah ini')->assertSee(route('admin.plans.edit', $plan), false);

    // Daftar tetap tersedia, dan tanpa denah aktif Peta kamar memakai Daftar.
    $this->get(route('laporan.rooms', ['tampilan' => 'daftar']))->assertOk()->assertSee('Peta per lantai')->assertDontSee('data-plan-map', false);
    $plan->update(['is_active' => false]);
    $this->get(route('laporan.rooms'))->assertOk()->assertSee('Peta per lantai')->assertDontSee('data-plan-map', false);
});

it('saves building / area boxes next to room boxes and keeps names unique per plan', function () {
    $plan = makePlan('Kawasan', null);
    $this->r101->update(['building' => 'Zona 1']);
    loggedIn($this->hr);

    $this->get(route('admin.plans.edit', $plan))->assertOk()->assertSee('data-buildings', false)->assertSee('Zona 1');

    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [
        ['room_id' => null, 'building' => 'Zona 1', 'x' => 5, 'y' => 5, 'w' => 20, 'h' => 20],
        ['room_id' => null, 'building' => 'Lobby', 'x' => 40, 'y' => 40, 'w' => 10, 'h' => 10],
        ['room_id' => $this->r201->id, 'building' => null, 'x' => 60, 'y' => 60, 'w' => 5, 'h' => 5],
    ]])->assertOk()->assertJson(['count' => 3]);
    expect(FloorPlanArea::whereNotNull('building')->pluck('building')->sort()->values()->all())->toBe(['Lobby', 'Zona 1'])->and(FloorPlanArea::whereNotNull('room_id')->count())->toBe(1);

    // Kotak harus kamar ATAU gedung; nama gedung ganda (huruf besar kecil sama) ditolak.
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [['room_id' => $this->r101->id, 'building' => 'X', 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5]]])->assertStatus(422);
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [['room_id' => null, 'building' => null, 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5]]])->assertStatus(422);
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [
        ['building' => 'Zona 1', 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5], ['building' => 'zona 1', 'x' => 9, 'y' => 1, 'w' => 5, 'h' => 5],
    ]])->assertStatus(422);

    // Daftar baru menggantikan: gedung yang tidak ada dilepas, kamar tetap bila disebut.
    $this->putJson(route('admin.plans.areas', $plan), ['areas' => [['building' => 'Zona 1', 'x' => 6, 'y' => 5, 'w' => 20, 'h' => 20]]])->assertOk();
    expect(FloorPlanArea::count())->toBe(1)->and(FloorPlanArea::first()->x)->toBe(6.0);

    // Gedung yang sama boleh ditandai di denah lain (mis. peta kawasan dan zonanya).
    $zone = makePlan('Zona 1', null);
    $this->putJson(route('admin.plans.areas', $zone), ['areas' => [['building' => 'Zona 1', 'x' => 10, 'y' => 10, 'w' => 30, 'h' => 30]]])->assertOk();
    expect(FloorPlanArea::where('building', 'Zona 1')->count())->toBe(2);
});

it('colors a building box by the issues of all rooms with that building and opens the building page', function () {
    $plan = makePlan('Kawasan', null);
    $this->r101->update(['building' => 'Zona 1']);
    $this->r102->update(['building' => 'Zona 1']);
    FloorPlanArea::create(['floor_plan_id' => $plan->id, 'building' => 'Zona 1', 'x' => 10, 'y' => 10, 'w' => 30, 'h' => 30]);
    $category = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->eng->id, 'sort' => 90]);
    foreach ([$this->r101, $this->r101, $this->r102, $this->r201] as $n => $room) {
        RoomIssue::create(['room_id' => $room->id, 'category_id' => $category->id, 'source' => 'tamu', 'description' => "Kendala gedung {$n}", 'priority' => 'biasa', 'reported_by' => $this->hr->id, 'assigned_department_id' => $this->eng->id, 'status' => 'baru']);
    }
    $range = ['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()];

    loggedIn($this->hr);
    fakeSchedules([]);
    $report = app(\App\Reports\RoomReport::class)->build($this->hr, array_values($range));
    expect($report['buildings']['zona 1']['count'])->toBe(3)->and($report['buildings']['zona 1']['rooms'])->toBe(2)->and($report['buildingMax'])->toBe(3);

    $html = $this->get(route('laporan.rooms', $range))->assertOk()->assertSee('data-plan-map', false)->assertSee('/laporan/kamar/gedung?', false)->getContent();
    expect($html)->toContain('Zona 1')->and($html)->toContain('3×')->and($html)->toContain('201'); // kamar 201 tanpa gedung tetap tercantum "Belum ada di denah"

    $this->get(route('laporan.building', ['nama' => 'Zona 1'] + $range))->assertOk()->assertSee('Kendala gedung 0')->assertSee('Kendala gedung 2')->assertDontSee('Kendala gedung 3');
    $this->get(route('laporan.building', $range))->assertNotFound();
});

it('sets the building of every room matching the filter, across pages, and refuses an unfiltered bulk change', function () {
    foreach (range(1, 35) as $n) {
        Room::create(['number' => '5'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'floor' => '5', 'sort' => 100 + $n]);
    }
    loggedIn($this->hr);

    $this->post(route('admin.rooms.building'), ['building' => 'Zona 3'])->assertSessionHasErrors('building');
    expect(Room::whereNotNull('building')->count())->toBe(0);

    $this->post(route('admin.rooms.building'), ['floor' => '5', 'building' => 'Zona 3'])->assertRedirect();
    expect(Room::where('building', 'Zona 3')->count())->toBe(35)->and(Room::where('floor', '1')->whereNotNull('building')->count())->toBe(0);

    $this->post(route('admin.rooms.building'), ['floor' => '5', 'building' => ''])->assertRedirect();
    expect(Room::whereNotNull('building')->count())->toBe(0);

    $this->get(route('admin.rooms.index', ['floor' => '5']))->assertOk()->assertSee('Atur gedung untuk 35 kamar yang cocok');
    $this->get(route('admin.rooms.index'))->assertOk()->assertDontSee('Atur gedung untuk');
});

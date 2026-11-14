<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IssueCategory;
use App\Models\Position;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function staffOf(Department $department, Role $role = Role::Staff): User
{
    return User::factory()->role($role)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
}

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->room = Room::create(['number' => '305', 'floor' => '3', 'type' => 'Deluxe', 'sort' => 1]);
    $this->ac = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->eng->id, 'sort' => 99]);
    $this->foStaff = staffOf($this->fo);
    $this->engStaff = staffOf($this->eng);
    $this->hkStaff = staffOf($this->hk);
});

function reportIssue($test, ?User $by = null, array $extra = []): RoomIssue
{
    return RoomIssue::create($extra + [
        'room_id' => $test->room->id, 'category_id' => $test->ac->id, 'source' => 'tamu', 'description' => 'Lampu kamar mandi mati',
        'priority' => 'biasa', 'reported_by' => ($by ?? $test->foStaff)->id, 'assigned_department_id' => $test->eng->id, 'status' => 'baru',
    ]);
}

it('lets Front Office staff log a room issue that goes to the category department', function () {
    loggedIn($this->foStaff);

    $this->post(route('kamar.issues.store'), ['room_id' => $this->room->id, 'category_id' => $this->ac->id, 'source' => 'tamu', 'priority' => 'mendesak', 'description' => 'Lampu kamar mandi mati'])
        ->assertRedirect();

    $issue = RoomIssue::firstOrFail();
    expect($issue->assigned_department_id)->toBe($this->eng->id)->and($issue->reported_by)->toBe($this->foStaff->id)
        ->and($issue->status)->toBe('baru')->and($issue->priority)->toBe('mendesak');

    $this->post(route('kamar.issues.store'), ['room_id' => $this->room->id, 'source' => 'tamu', 'priority' => 'biasa', 'description' => ''])->assertSessionHasErrors('description');
    $this->room->update(['is_active' => false]);
    $this->post(route('kamar.issues.store'), ['room_id' => $this->room->id, 'source' => 'tamu', 'priority' => 'biasa', 'description' => 'x'])->assertSessionHasErrors('room_id');
});

it('shows each user only the issues they reported or that go to their department', function () {
    reportIssue($this);

    loggedIn($this->engStaff);
    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('Lampu kamar mandi mati');
    $this->post('/logout');

    loggedIn($this->hkStaff);
    $this->get(route('kamar.issues.index'))->assertOk()->assertDontSee('Lampu kamar mandi mati');
    $this->get(route('kamar.issues.show', RoomIssue::first()))->assertForbidden();
    $this->post('/logout');

    loggedIn($this->foStaff);
    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('Lampu kamar mandi mati');
    $this->post('/logout');

    loggedIn(User::factory()->role(Role::Gm)->create());
    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('Lampu kamar mandi mati');
});

it('needs the issue to be claimed before work is logged, then takes Done, Ongoing or Pending', function () {
    $issue = reportIssue($this);

    loggedIn($this->engStaff);
    // Belum diambil: formulir status belum tersedia.
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertDontSee('Status pekerjaan');
    $this->post(route('kamar.issues.update', $issue), ['status' => 'ongoing'])->assertForbidden();

    $this->post(route('kamar.issues.claim', $issue))->assertRedirect();
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee('Status pekerjaan')->assertSee('Ongoing')->assertSee('Pending')->assertSee('Done');

    $this->post(route('kamar.issues.update', $issue), ['status' => 'ongoing', 'body' => 'Freon diisi'])->assertRedirect();
    expect($issue->refresh()->status)->toBe('dikerjakan');

    // Pending wajib beralasan.
    $this->post(route('kamar.issues.update', $issue), ['status' => 'pending', 'body' => ''])->assertSessionHasErrors('body');
    $this->post(route('kamar.issues.update', $issue), ['status' => 'pending', 'body' => 'Menunggu suku cadang'])->assertRedirect();
    expect($issue->refresh()->status)->toBe('tertunda')->and($issue->updates->last()->kind)->toBe('tunda');

    $this->post(route('kamar.issues.update', $issue), ['status' => 'bogus'])->assertSessionHasErrors('status');
    $this->post(route('kamar.issues.update', $issue), ['status' => 'done', 'body' => 'AC normal'])->assertRedirect();
    expect($issue->refresh()->status)->toBe('selesai')->and($issue->completed_by)->toBe($this->engStaff->id)->and($issue->completed_at)->not->toBeNull();

    // Done = terkunci untuk perubahan berikutnya.
    $this->post(route('kamar.issues.update', $issue), ['status' => 'ongoing'])->assertForbidden();
});

it('keeps the work form to the person who claimed it', function () {
    $issue = reportIssue($this);
    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.claim', $issue));
    $this->post('/logout');

    $teammate = staffOf($this->eng);
    loggedIn($teammate);
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertDontSee('Status pekerjaan');
    $this->post(route('kamar.issues.update', $issue), ['status' => 'done'])->assertForbidden();
    expect($issue->refresh()->status)->toBe('dikerjakan');
    $this->post('/logout');

    // HOD departemennya tetap bisa mengisi.
    $hod = staffOf($this->eng, Role::Hod);
    $hod->departments()->attach($this->eng->id);
    loggedIn($hod);
    $this->post(route('kamar.issues.update', $issue), ['status' => 'ongoing', 'body' => 'Dicek HOD'])->assertRedirect();
});

it('does not let other departments, the reporter or the GM log work', function () {
    $issue = reportIssue($this);

    $issue->update(['claimed_by' => $this->engStaff->id, 'status' => 'dikerjakan']);
    foreach ([$this->hkStaff, $this->foStaff, User::factory()->role(Role::Gm)->create()] as $user) {
        loggedIn($user);
        $this->post(route('kamar.issues.update', $issue), ['status' => 'done', 'body' => 'Coba'])->assertForbidden();
        $this->post('/logout');
    }
    expect($issue->refresh()->updates)->toHaveCount(0);
});

it('lets only HR or the Administrator reopen a finished issue', function () {
    $issue = reportIssue($this, null, ['status' => 'selesai', 'completed_at' => now()]);

    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.reopen', $issue))->assertForbidden();
    $this->post('/logout');

    loggedIn(User::factory()->role(Role::Hr)->create());
    $this->post(route('kamar.issues.reopen', $issue))->assertRedirect();
    expect($issue->refresh()->status)->toBe('dikerjakan')->and($issue->completed_at)->toBeNull();
});

it('flags new issues for the assigned department in the menu and on the list', function () {
    reportIssue($this);
    reportIssue($this, null, ['description' => 'Keran bocor']);

    loggedIn($this->engStaff);
    $page = $this->get(route('kamar.issues.index'))->assertOk()
        ->assertSee('2 baru untuk Anda', false)->assertSee('kendala baru menunggu Anda')->assertSee('Untuk departemen Anda');
    expect($this->engStaff->newRoomIssueCount())->toBe(2);
    $this->post('/logout');

    // Departemen lain dan pelapor tidak mendapat penanda.
    foreach ([$this->hkStaff, $this->foStaff] as $user) {
        loggedIn($user);
        $this->get(route('kamar.issues.index'))->assertOk()->assertDontSee('baru untuk Anda')->assertDontSee('menunggu Anda');
        $this->post('/logout');
    }

    // Setelah dikerjakan, penanda hilang.
    RoomIssue::query()->update(['status' => 'dikerjakan']);
    $fresh = User::find($this->engStaff->id);
    expect($fresh->newRoomIssueCount())->toBe(0);
});

it('lets only one person in the department claim an issue', function () {
    $issue = reportIssue($this);
    $second = staffOf($this->eng);

    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.claim', $issue))->assertRedirect();
    $issue->refresh();
    expect($issue->claimed_by)->toBe($this->engStaff->id)->and($issue->status)->toBe('dikerjakan')->and($issue->updates->last()->kind)->toBe('ambil');
    $this->get(route('kamar.issues.show', $issue))->assertSee($this->engStaff->name())->assertSee('sedang mengerjakan kendala ini')->assertDontSee('Saya ambil');
    $this->post('/logout');

    // Rekan lain tidak bisa mengambilnya lagi, dan melihat siapa yang mengambil.
    loggedIn($second);
    $this->post(route('kamar.issues.claim', $issue))->assertForbidden();
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertDontSee('Saya ambil');
    expect($issue->refresh()->claimed_by)->toBe($this->engStaff->id);
    $this->post('/logout');

    // Departemen lain tidak bisa mengambil.
    loggedIn($this->hkStaff);
    $this->post(route('kamar.issues.claim', $issue))->assertForbidden();
});

it('shows the claim button to department members and keeps a race from giving the issue to two people', function () {
    $issue = reportIssue($this);
    loggedIn($this->engStaff);
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee('Saya ambil');

    // Orang lain mengambilnya tepat sebelum permintaan ini diproses: pembaruan atomik gagal, kendala tetap milik yang pertama.
    $other = staffOf($this->eng);
    RoomIssue::whereKey($issue->id)->update(['claimed_by' => $other->id, 'claimed_at' => now(), 'status' => 'dikerjakan']);
    $this->post(route('kamar.issues.claim', $issue))->assertForbidden();
    expect($issue->refresh()->claimed_by)->toBe($other->id);
});

it('lets the claimer, the HOD or HR release a claim, and reverts to new when no work was logged', function () {
    $issue = reportIssue($this);
    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.claim', $issue));
    $this->post('/logout');

    $teammate = staffOf($this->eng);
    loggedIn($teammate);
    $this->post(route('kamar.issues.release', $issue))->assertForbidden();
    $this->post('/logout');

    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.release', $issue))->assertRedirect();
    $issue->refresh();
    expect($issue->claimed_by)->toBeNull()->and($issue->status)->toBe('baru');
    $this->post('/logout');

    // Dilepas HOD setelah ada catatan pekerjaan: tetap Dikerjakan.
    $hod = staffOf($this->eng, Role::Hod);
    $hod->departments()->attach($this->eng->id);
    loggedIn($teammate);
    $this->post(route('kamar.issues.claim', $issue));
    $this->post(route('kamar.issues.update', $issue), ['status' => 'ongoing', 'body' => 'Cek awal']);
    $this->post('/logout');
    loggedIn($hod);
    $this->post(route('kamar.issues.release', $issue->refresh()))->assertRedirect();
    expect($issue->refresh()->claimed_by)->toBeNull()->and($issue->status)->toBe('dikerjakan');
});

function storeIssue($test, array $extra = [])
{
    return $test->post(route('kamar.issues.store'), $extra + ['room_id' => $test->room->id, 'category_id' => $test->ac->id, 'source' => 'tamu', 'priority' => 'biasa', 'description' => 'AC masih panas']);
}

it('tells the reporter about an open issue of the same room and category before creating a duplicate', function () {
    $open = reportIssue($this);
    $open->update(['status' => 'dikerjakan', 'claimed_by' => $this->engStaff->id]);
    $other = IssueCategory::create(['name' => 'TV uji', 'department_id' => $this->eng->id, 'sort' => 98]);

    loggedIn($this->foStaff);
    $this->getJson(route('kamar.issues.duplicate', ['room_id' => $this->room->id, 'category_id' => $this->ac->id]))
        ->assertOk()->assertJson(['found' => true, 'id' => $open->id, 'status' => 'Ongoing', 'claimer' => $this->engStaff->name()]);
    $this->getJson(route('kamar.issues.duplicate', ['room_id' => $this->room->id, 'category_id' => $other->id]))->assertOk()->assertJson(['found' => false]);

    $open->update(['status' => 'selesai']);
    $this->getJson(route('kamar.issues.duplicate', ['room_id' => $this->room->id, 'category_id' => $this->ac->id]))->assertOk()->assertJson(['found' => false]);
});

it('refuses a duplicate until the reporter chooses to add a report or create a new issue', function () {
    reportIssue($this);

    loggedIn($this->foStaff);
    storeIssue($this)->assertSessionHasErrors('duplicate');
    expect(RoomIssue::count())->toBe(1);

    storeIssue($this, ['force_new' => 1])->assertRedirect();
    expect(RoomIssue::count())->toBe(2);
});

it('adds a repeat report to the existing issue instead of creating another one', function () {
    $first = reportIssue($this);
    $first->update(['status' => 'dikerjakan', 'claimed_by' => $this->engStaff->id]);
    $reporter = staffOf($this->hk); // bukan pelapor dan bukan departemen penanggung jawab

    loggedIn($reporter);
    storeIssue($this, ['merge_into' => $first->id, 'priority' => 'mendesak', 'description' => 'Tamu komplain lagi'])->assertRedirect();

    expect(RoomIssue::count())->toBe(1);
    $first->refresh();
    expect($first->priority)->toBe('mendesak')->and($first->updates->last()->kind)->toBe('laporan')->and($first->updates->last()->body)->toContain('Tamu komplain lagi')
        ->and($first->updates->last()->user_id)->toBe($reporter->id);

    // Yang menambahkan laporan kini bisa melihat kendalanya; teknisi yang mengerjakan melihat laporan barunya.
    $this->get(route('kamar.issues.show', $first))->assertOk();
    $this->post('/logout');
    loggedIn($this->engStaff);
    $this->get(route('kamar.issues.show', $first))->assertOk()->assertSee('Tamu komplain lagi');
});

it('refuses to merge into an issue of another room, category or one that is already done', function () {
    $first = reportIssue($this);
    $otherRoom = Room::create(['number' => '306', 'floor' => '3', 'sort' => 2]);

    loggedIn($this->foStaff);
    storeIssue($this, ['merge_into' => $first->id, 'room_id' => $otherRoom->id])->assertSessionHasErrors('merge_into');
    $first->update(['status' => 'selesai']);
    storeIssue($this, ['merge_into' => $first->id])->assertSessionHasErrors('merge_into');
    expect($first->refresh()->updates)->toHaveCount(0);
});

it('marks an issue as recurring when the same category came back in the same room within 30 days', function () {
    $old = reportIssue($this, null, ['status' => 'selesai', 'completed_at' => now()->subDays(8)]);
    $old->forceFill(['created_at' => now()->subDays(10)])->save();
    $ancient = reportIssue($this, null, ['status' => 'selesai', 'completed_at' => now()->subDays(40)]);
    $ancient->forceFill(['created_at' => now()->subDays(45)])->save();

    loggedIn($this->foStaff);
    storeIssue($this)->assertRedirect();
    $new = RoomIssue::orderByDesc('id')->first();
    expect($new->recurrence)->toBe(2); // satu kendala serupa dalam 30 hari; yang 45 hari lalu tidak dihitung

    $this->get(route('kamar.issues.show', $new))->assertOk()->assertSee('Berulang ke-2');
});

it('separates the track record into similar issues, own department work and other departments', function () {
    $tv = IssueCategory::create(['name' => 'TV uji', 'department_id' => $this->eng->id, 'sort' => 98]);
    $linen = IssueCategory::create(['name' => 'Linen uji', 'department_id' => $this->hk->id, 'sort' => 97]);
    $ac1 = reportIssue($this, null, ['status' => 'selesai', 'completed_at' => now(), 'description' => 'AC lama tidak dingin']);
    $ac1->updates()->create(['user_id' => $this->engStaff->id, 'body' => 'Freon diisi', 'kind' => 'selesai']);
    $ac2 = reportIssue($this, null, ['description' => 'AC bunyi keras']);
    reportIssue($this, null, ['category_id' => $tv->id, 'description' => 'TV tidak menyala']);
    reportIssue($this, null, ['category_id' => $linen->id, 'description' => 'Linen perlu diganti', 'assigned_department_id' => $this->hk->id]);

    loggedIn($this->engStaff);
    $html = $this->get(route('kamar.issues.show', $ac2))->assertOk()
        ->assertSee('Track record Kamar 305')->assertSee('Kendala sejenis')->assertSee('AC lama tidak dingin')->assertSee('Freon diisi')
        ->assertSee('Pekerjaan departemen Anda di lokasi ini')->assertSee('TV tidak menyala')
        ->assertSee('Kendala departemen lain')->assertSee('Linen perlu diganti')->getContent();

    // Tidak tercampur: pekerjaan Engineering muncul sebelum kendala departemen lain, dan kendala yang sedang dibuka tidak masuk daftar.
    expect(strpos($html, 'TV tidak menyala'))->toBeLessThan(strpos($html, 'Kendala departemen lain'))
        ->and(strpos($html, 'Kendala departemen lain'))->toBeLessThan(strpos($html, 'Linen perlu diganti'));
    expect(substr_count($html, 'AC bunyi keras'))->toBeLessThan(3);
});

it('lets any issue user open the full track record of a room, filtered by category or department', function () {
    $ac = reportIssue($this, null, ['description' => 'AC lama', 'status' => 'selesai', 'completed_at' => now()]);
    $ac->updates()->create(['user_id' => $this->engStaff->id, 'body' => 'Kompresor diganti', 'kind' => 'selesai']);
    $linen = IssueCategory::create(['name' => 'Linen uji', 'department_id' => $this->hk->id, 'sort' => 97]);
    reportIssue($this, null, ['category_id' => $linen->id, 'description' => 'Linen kotor', 'assigned_department_id' => $this->hk->id]);

    loggedIn($this->foStaff);
    $this->get(route('kamar.room', $this->room))->assertOk()->assertSee('Track record kamar 305')->assertSee('AC lama')->assertSee('Kompresor diganti')->assertSee('Linen kotor');
    $this->get(route('kamar.room', ['room' => $this->room->id, 'department_id' => $this->hk->id]))->assertOk()->assertSee('Linen kotor')->assertDontSee('AC lama');
    $this->get(route('kamar.room', ['room' => $this->room->id, 'category_id' => $this->ac->id]))->assertOk()->assertSee('AC lama')->assertDontSee('Linen kotor');
});

it('notes on the earlier issue that the same problem came back, with a link to the new one', function () {
    $old = reportIssue($this, null, ['status' => 'selesai', 'completed_at' => now()->subDays(3)]);
    $old->forceFill(['created_at' => now()->subDays(5)])->save();

    loggedIn($this->foStaff);
    storeIssue($this, ['description' => 'AC rusak lagi'])->assertRedirect();
    $new = RoomIssue::orderByDesc('id')->first();

    $entry = $old->refresh()->updates->last();
    expect($new->recurrence)->toBe(2)->and($entry->kind)->toBe('berulang')->and($entry->related_issue_id)->toBe($new->id)->and($entry->user_id)->toBeNull();
    $this->post('/logout');

    loggedIn($this->engStaff);
    $this->get(route('kamar.issues.show', $old))->assertOk()->assertSee('Muncul lagi sebagai kendala #'.$new->id)->assertSee(route('kamar.issues.show', $new), false);
});

it('shows the claimer only the track record of their own department, collapsed by default', function () {
    $ac = reportIssue($this, null, ['description' => 'AC bunyi keras']);
    $engPast = reportIssue($this, null, ['description' => 'Kompresor lama', 'status' => 'selesai', 'completed_at' => now()]);
    $engPast->updates()->create(['user_id' => $this->engStaff->id, 'body' => 'Kompresor diganti', 'kind' => 'selesai']);
    reportIssue($this, null, ['description' => 'Linen perlu diganti', 'assigned_department_id' => $this->hk->id]);

    loggedIn($this->engStaff);
    $this->get(route('kamar.issues.show', $ac))->assertOk()->assertSee('Track record Kamar 305')->assertSee('Linen perlu diganti');

    $this->post(route('kamar.issues.claim', $ac))->assertRedirect();
    $html = $this->get(route('kamar.issues.show', $ac))->assertOk()
        ->assertDontSee('Kendala sejenis')->assertDontSee('Linen perlu diganti')->assertSee('Status pekerjaan')
        ->assertSee('Track record Kamar 305')->assertSee('Engineering')->assertSee('Kompresor diganti')->getContent();
    // Kartu dilipat secara bawaan: <details> tanpa atribut open.
    expect($html)->toContain('<details class="card group mb-6 overflow-hidden">')->and($html)->not->toContain('<details class="card group mb-6 overflow-hidden" open');
    $this->post('/logout');

    // Rekan lain yang belum mengambil tetap melihat track record lengkap yang dipisah.
    loggedIn(staffOf($this->eng));
    $this->get(route('kamar.issues.show', $ac))->assertOk()->assertSee('Kendala sejenis')->assertSee('Linen perlu diganti');
});

it('puts the status form first, ordered Done, Ongoing, Pending, with Lepas next to Simpan and no forward form', function () {
    $issue = reportIssue($this);
    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.claim', $issue));

    $html = $this->get(route('kamar.issues.show', $issue))->assertOk()
        ->assertDontSee('Teruskan ke departemen lain')->assertSee('id="release-form"', false)->getContent();

    $done = strpos($html, 'value="done"');
    $ongoing = strpos($html, 'value="ongoing"');
    $pending = strpos($html, 'value="pending"');
    expect($done)->toBeLessThan($ongoing)->and($ongoing)->toBeLessThan($pending);

    // Urutan di halaman: kartu status pekerjaan, lalu Simpan, lalu Lepas; tanpa kartu pengambil terpisah di bawahnya.
    $save = strpos($html, '>Simpan<');
    $release = strpos($html, 'form="release-form"');
    expect(strpos($html, 'Status pekerjaan'))->toBeLessThan($save)->and($save)->toBeLessThan($release);
    expect(substr_count($html, 'sedang mengerjakan kendala ini'))->toBe(1);

    // Rekan yang tidak bisa mengisi hanya melihat keterangan siapa yang mengerjakan, tanpa tombol.
    $this->post('/logout');
    loggedIn(staffOf($this->eng));
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee('sedang mengerjakan kendala ini')->assertDontSee('release-form')->assertDontSee('Status pekerjaan');
});

it('shows the camera, gallery and notes buttons on the work form', function () {
    $issue = reportIssue($this);
    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.claim', $issue));

    $this->get(route('kamar.issues.show', $issue))->assertOk()
        ->assertSee('data-photo-pick="camera"', false)->assertSee('data-photo-pick="gallery"', false)->assertSee('data-note-toggle', false)
        ->assertSee('Kamera')->assertSee('Galeri')->assertSee('Catatan')->assertSee('multipart/form-data', false);
});

it('stores re-encoded photos with a status update and shows them to issue users', function () {
    Storage::fake('local');
    $issue = reportIssue($this);
    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.claim', $issue));

    $this->post(route('kamar.issues.update', $issue), [
        'status' => 'ongoing', 'body' => 'Kompresor dicek',
        'photos' => [UploadedFile::fake()->image('a.jpg', 1800, 1200), UploadedFile::fake()->image('b.png', 600, 400)],
    ])->assertRedirect();

    $update = $issue->refresh()->updates()->where('kind', 'catatan')->firstOrFail();
    expect($update->photos)->toHaveCount(2);
    $photo = $update->photos->first();
    Storage::disk('local')->assertExists($photo->path);
    $info = getimagesizefromstring(Storage::disk('local')->get($photo->path));
    expect($info['mime'])->toBe('image/jpeg')->and(max($info[0], $info[1]))->toBeLessThanOrEqual(1600);

    // Timeline menampilkan foto; pengguna kendala kamar lain (mis. FO) bisa membukanya lewat track record; tamu tanpa login tidak.
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee(route('kamar.issues.photo', [$issue, $photo]), false);
    $this->get(route('kamar.issues.photo', [$issue, $photo]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->post('/logout');
    loggedIn($this->foStaff);
    $this->get(route('kamar.issues.photo', [$issue, $photo]))->assertOk();
    $this->post('/logout');
    $this->get(route('kamar.issues.photo', [$issue, $photo]))->assertRedirect();

    // Foto milik kendala lain tidak bisa dibuka lewat kendala yang salah.
    $other = reportIssue($this, null, ['description' => 'Kendala lain']);
    loggedIn($this->engStaff);
    $this->get(route('kamar.issues.photo', [$other, $photo]))->assertNotFound();
});

it('refuses too many or fake photos and leaves the status unchanged', function () {
    Storage::fake('local');
    $issue = reportIssue($this);
    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.claim', $issue));

    $four = collect(range(1, 4))->map(fn ($n) => UploadedFile::fake()->image("f{$n}.jpg", 200, 200))->all();
    $this->post(route('kamar.issues.update', $issue), ['status' => 'done', 'photos' => $four])->assertSessionHasErrors('photos');

    $fake = UploadedFile::fake()->createWithContent('bukan-foto.jpg', 'ini bukan gambar sungguhan');
    $this->post(route('kamar.issues.update', $issue), ['status' => 'done', 'photos' => [$fake]])->assertSessionHasErrors();

    expect($issue->refresh()->status)->toBe('dikerjakan')->and(\App\Models\RoomIssuePhoto::count())->toBe(0);
});

it('prunes room issue photos older than the retention period', function () {
    Storage::fake('local');
    $issue = reportIssue($this);
    $update = $issue->updates()->create(['user_id' => $this->engStaff->id, 'body' => 'x', 'kind' => 'catatan']);
    $service = app(\App\Rooms\RoomIssuePhotoService::class);
    $old = $service->store($update, UploadedFile::fake()->image('o.jpg', 100, 100), $this->engStaff);
    $new = $service->store($update, UploadedFile::fake()->image('n.jpg', 100, 100), $this->engStaff);
    $old->forceFill(['created_at' => now()->subDays(120)])->save();

    expect($service->prune(90))->toBe(1);
    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($new->path);
});

it('keeps the collapsed track record card for the claimer even when the department has no earlier work in the room', function () {
    $issue = reportIssue($this);
    reportIssue($this, null, ['description' => 'Linen perlu diganti', 'assigned_department_id' => $this->hk->id]);

    loggedIn($this->engStaff);
    $this->post(route('kamar.issues.claim', $issue));
    $this->get(route('kamar.issues.show', $issue))->assertOk()
        ->assertSee('Track record Kamar 305')->assertSee('Belum ada track record sebelumnya di lokasi ini.')->assertDontSee('Linen perlu diganti');
});

it('hides the filter form from Staff but keeps it for managers', function () {
    reportIssue($this);

    loggedIn($this->engStaff);
    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('Lampu kamar mandi mati')->assertDontSee('id="f-status"', false)->assertDontSee('Departemen penanggung jawab');
    $this->post('/logout');

    $hod = staffOf($this->eng, Role::Hod);
    $hod->departments()->attach($this->eng->id);
    loggedIn($hod);
    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('id="f-status"', false)->assertSee('Departemen penanggung jawab');
});

it('builds a printable chronology report with ordered events, gaps and response and handling times', function () {
    Storage::fake('local');
    $issue = reportIssue($this);
    $start = now()->startOfDay()->setTime(8, 0);
    $issue->forceFill(['created_at' => $start, 'status' => 'selesai', 'claimed_by' => $this->engStaff->id, 'completed_at' => $start->copy()->setTime(12, 30), 'completed_by' => $this->engStaff->id])->save();
    $make = fn (string $kind, string $body, string $time) => tap($issue->updates()->create(['user_id' => $this->engStaff->id, 'department_id' => $this->eng->id, 'body' => $body, 'kind' => $kind]), fn ($u) => $u->forceFill(['created_at' => $start->copy()->setTimeFromTimeString($time)])->save());
    $make('ambil', 'Teknisi mengambil kendala ini.', '08:30');
    $make('catatan', 'Cek kompresor', '09:00');
    $pending = $make('tunda', 'Menunggu suku cadang', '10:00');
    $done = $make('selesai', 'AC normal kembali', '12:30');
    app(\App\Rooms\RoomIssuePhotoService::class)->store($done, UploadedFile::fake()->image('bukti.jpg', 600, 400), $this->engStaff);

    loggedIn($this->engStaff);
    $html = $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee('Cetak riwayat')->assertSee('Buat Chronology Report')->assertSee(route('kamar.issues.chronology', $issue), false)->getContent();

    $html = $this->get(route('kamar.issues.chronology', $issue))->assertOk()
        ->assertSee('Riwayat Penanganan Kendala')->assertSee('Cetak / simpan PDF')->assertSee('Kamar 305', false)->assertSee('Lampu kamar mandi mati')
        ->assertSee('Menunggu suku cadang')->assertSee('AC normal kembali')->assertSee('Dibuat oleh')->getContent();

    // Urutan waktu, dan waktu respons 30 m, penanganan 4 j, total 4 j 30 m.
    $order = ['Kendala dicatat', 'Diambil', 'Ongoing', 'Pending', 'Done'];
    $positions = array_map(fn ($label) => strpos($html, '<span class="font-semibold text-slate-900">'.$label.'</span>'), $order);
    expect($positions)->each->not->toBeFalse()->and($positions)->toBe(collect($positions)->sort()->values()->all());
    expect($html)->toContain('30 m')->toContain('4 j')->toContain('4 j 30 m')->toContain('+ 1 j')->toContain('/kamar/kendala/'.$issue->id.'/foto/');
});

it('keeps the chronology report to people who may view the issue', function () {
    $issue = reportIssue($this);
    loggedIn($this->hkStaff);
    $this->get(route('kamar.issues.chronology', $issue))->assertForbidden();
    $this->post('/logout');

    loggedIn($this->foStaff); // pelapor
    $this->get(route('kamar.issues.chronology', $issue))->assertOk()->assertSee('belum Done');
});

function staffWithPosition(Department $department, Position $position): User
{
    return User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($department)->create(['checklist_position_id' => $position->id])->id]);
}

it('logs an issue at a location other than a room', function () {
    loggedIn($this->foStaff);

    $this->post(route('kamar.issues.store'), ['place_type' => 'location', 'location' => 'Lobby', 'category_id' => $this->ac->id, 'source' => 'staff', 'priority' => 'biasa', 'description' => 'Lampu lobby redup'])
        ->assertRedirect();

    $issue = RoomIssue::firstOrFail();
    expect($issue->room_id)->toBeNull()->and($issue->location)->toBe('Lobby')->and($issue->assigned_department_id)->toBe($this->eng->id)->and($issue->placeTitle())->toBe('Lobby');

    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('Lobby')->assertSee('Lokasi lain');
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee('Lobby')->assertSee('Lampu lobby redup');
    $this->get(route('kamar.place', ['nama' => 'Lobby']))->assertOk()->assertSee('Track record')->assertSee('Lampu lobby redup');
    $this->get(route('kamar.issues.chronology', $issue))->assertOk()->assertSee('Lobby');

    $this->post(route('kamar.issues.store'), ['place_type' => 'location', 'location' => '  ', 'source' => 'staff', 'priority' => 'biasa', 'description' => 'x'])->assertSessionHasErrors('location');
    $this->post(route('kamar.issues.store'), ['place_type' => 'room', 'source' => 'staff', 'priority' => 'biasa', 'description' => 'x'])->assertSessionHasErrors('room_id');
});

it('treats the same location and category as one open issue, ignoring letter case', function () {
    loggedIn($this->foStaff);
    $payload = ['place_type' => 'location', 'location' => 'Lobby', 'category_id' => $this->ac->id, 'source' => 'staff', 'priority' => 'biasa', 'description' => 'AC lobby mati'];
    $this->post(route('kamar.issues.store'), $payload)->assertRedirect();
    $first = RoomIssue::firstOrFail();

    $this->post(route('kamar.issues.store'), ['location' => 'lobby'] + $payload)->assertSessionHasErrors('duplicate');
    $this->getJson(route('kamar.issues.duplicate', ['location' => 'LOBBY', 'category_id' => $this->ac->id]))->assertJsonPath('found', true)->assertJsonPath('id', $first->id);
    $this->post(route('kamar.issues.store'), ['location' => 'lobby', 'merge_into' => $first->id, 'description' => 'Masih panas'] + $payload)->assertRedirect(route('kamar.issues.show', $first));

    expect(RoomIssue::count())->toBe(1)->and($first->updates()->where('kind', 'laporan')->count())->toBe(1);
});

it('keeps issues at other locations out of the room map', function () {
    reportIssue($this);
    reportIssue($this, null, ['room_id' => null, 'location' => 'Lobby']);
    $range = ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()];

    $report = app(\App\Reports\RoomReport::class)->build(userWithRole(Role::Admin), array_values($range));

    expect($report['total'])->toBe(1);
});

it('forwards an issue to one position so only people with that position can take it', function () {
    $position = Position::factory()->for($this->eng)->create(['name' => 'Chief Engineer']);
    $holder = staffWithPosition($this->eng, $position);
    $mate = staffOf($this->eng);
    $hod = staffOf($this->eng, Role::Hod);
    $hod->departments()->attach($this->eng->id);

    loggedIn($this->foStaff);
    $this->post(route('kamar.issues.store'), ['room_id' => $this->room->id, 'source' => 'tamu', 'priority' => 'biasa', 'description' => 'Genset bunyi', 'target_type' => 'position', 'assigned_position_id' => $position->id])->assertRedirect();
    $issue = RoomIssue::firstOrFail();
    expect($issue->assigned_position_id)->toBe($position->id)->and($issue->assigned_department_id)->toBe($this->eng->id)->and($issue->assigned_employee_id)->toBeNull();
    $this->post('/logout');

    loggedIn($mate); // satu departemen, jabatan lain: tidak melihat dan tidak bisa mengambil
    $this->get(route('kamar.issues.index'))->assertOk()->assertDontSee('Genset bunyi');
    $this->get(route('kamar.issues.show', $issue))->assertForbidden();
    $this->post(route('kamar.issues.claim', $issue))->assertForbidden();
    $this->post('/logout');

    loggedIn($holder);
    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('Genset bunyi')->assertSee('Untuk jabatan Anda')->assertSee('Chief Engineer');
    expect($holder->newRoomIssueCount())->toBe(1);
    $this->post(route('kamar.issues.claim', $issue))->assertRedirect();
    expect($issue->fresh()->claimed_by)->toBe($holder->id);
    $this->post('/logout');

    loggedIn($hod); // HOD memegang semua tujuan di departemennya
    $this->get(route('kamar.issues.show', $issue))->assertOk();

    $this->post(route('kamar.issues.store'), ['room_id' => $this->room->id, 'source' => 'tamu', 'priority' => 'biasa', 'description' => 'x', 'target_type' => 'position'])->assertSessionHasErrors('assigned_position_id');
});

it('forwards an issue to one employee who has an account', function () {
    $target = staffOf($this->eng);
    $mate = staffOf($this->eng);

    loggedIn($this->foStaff);
    $this->post(route('kamar.issues.store'), ['room_id' => $this->room->id, 'source' => 'tamu', 'priority' => 'mendesak', 'description' => 'Keran bocor', 'target_type' => 'employee', 'assigned_employee_id' => $target->employee_id])->assertRedirect();
    $issue = RoomIssue::firstOrFail();
    expect($issue->assigned_employee_id)->toBe($target->employee_id)->and($issue->assigned_department_id)->toBe($this->eng->id)->and($issue->targetLabel())->toContain($target->employee->name);

    $withoutAccount = Employee::factory()->for($this->eng)->create();
    $this->post(route('kamar.issues.store'), ['room_id' => $this->room->id, 'source' => 'tamu', 'priority' => 'biasa', 'description' => 'y', 'target_type' => 'employee', 'assigned_employee_id' => $withoutAccount->id])->assertSessionHasErrors('assigned_employee_id');
    $this->post('/logout');

    loggedIn($mate);
    $this->get(route('kamar.issues.show', $issue))->assertForbidden();
    $this->post(route('kamar.issues.claim', $issue))->assertForbidden();
    $this->post('/logout');

    loggedIn($target);
    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('Keran bocor')->assertSee('Untuk Anda');
    expect($target->newRoomIssueCount())->toBe(1);
    $this->post(route('kamar.issues.claim', $issue))->assertRedirect();
    expect($issue->fresh()->claimed_by)->toBe($target->id);
});

it('lets only the Administrator delete an issue, removes its work history and photos, keeps the chat thread, and logs everything', function () {
    Storage::fake('local');
    config(['checklist.photos.disk' => 'local']);
    $issue = reportIssue($this);
    $update = $issue->updates()->create(['user_id' => $this->engStaff->id, 'department_id' => $this->eng->id, 'body' => 'Freon diisi 300 g', 'kind' => 'catatan']);
    $photo = app(\App\Rooms\RoomIssuePhotoService::class)->store($update, UploadedFile::fake()->image('bukti.jpg', 600, 400), $this->engStaff);
    Storage::disk('local')->assertExists($photo->path);
    $thread = \App\Models\ChatThread::create(['room_issue_id' => $issue->id, 'created_by' => $this->engStaff->id]);
    $message = $thread->messages()->create(['user_id' => $this->engStaff->id, 'body' => 'Lihat kendala ini', 'room_issue_id' => $issue->id]);
    $admin = staffOf($this->fo, Role::Admin);

    // HR, HOD, dan pelapornya tidak bisa; ikon hapus tidak ditampilkan.
    foreach ([staffOf($this->fo, Role::Hr), staffOf($this->eng, Role::Hod), $this->foStaff] as $user) {
        loggedIn($user);
        $this->get(route('kamar.issues.show', $issue))->assertOk()->assertDontSee('action="'.route('kamar.issues.destroy', $issue).'"', false)->assertDontSee('Hapus kendala');
        $this->delete(route('kamar.issues.destroy', $issue))->assertForbidden();
        $this->post('/logout');
    }
    expect(RoomIssue::whereKey($issue->id)->exists())->toBeTrue();

    loggedIn($admin);
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee('action="'.route('kamar.issues.destroy', $issue).'"', false)->assertSee('Hapus kendala');
    $this->delete(route('kamar.issues.destroy', $issue))->assertRedirect(route('kamar.issues.index'))->assertSessionHas('status');

    expect(RoomIssue::whereKey($issue->id)->exists())->toBeFalse()
        ->and(\App\Models\RoomIssueUpdate::where('room_issue_id', $issue->id)->count())->toBe(0)
        ->and(\App\Models\RoomIssuePhoto::whereKey($photo->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($photo->path);

    // Obrolan tetap ada dengan judul yang jelas; kartu kendalanya menjadi "sudah dihapus".
    expect($thread->fresh()->subject)->toContain('Kendala #'.$issue->id)->toContain('dihapus')->and($thread->fresh()->room_issue_id)->toBeNull()
        ->and($message->fresh()->room_issue_id)->toBeNull();

    // Log aplikasi: penghapusan kendala (dengan isi pokoknya) dan catatan pekerjaan yang ikut hilang.
    $deleted = \App\Models\AuditLog::where('action', 'room_issue.deleted')->where('subject_id', $issue->id)->firstOrFail();
    expect($deleted->user_id)->toBe($admin->id)->and($deleted->subject_label)->toContain('Kamar 305')->toContain('#'.$issue->id)
        ->and($deleted->changes)->toHaveKey('description')->and($deleted->changes['description'][0])->toBe('Lampu kamar mandi mati')->and($deleted->changes['description'][1])->toBeNull();
    $work = \App\Models\AuditLog::where('action', 'room_issue_update.deleted')->where('subject_id', $issue->id)->firstOrFail();
    expect($work->subject_label)->toContain('1 catatan pekerjaan, 1 foto')->and(collect($work->changes)->flatten()->filter()->all())->toContain('Freon diisi 300 g');

    $this->get(route('kamar.issues.show', $issue))->assertNotFound();
});

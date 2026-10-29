<?php

use App\Checklists\ChecklistSettings;
use App\Enums\ChecklistStatus;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\ChecklistItemPhoto;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    Task::factory()->for($this->gsa)->count(2)->create();
    $ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    Task::factory()->for($ra)->create();
    $this->budi = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso']);
    $this->sari = Employee::factory()->for($this->hk)->create();
    $this->hod = User::factory()->hodOf($this->fo)->create();
    $this->checklist = makeChecklist($this->budi, $this->gsa, null, null);
    $this->item = $this->checklist->items->first();
});

function photo(int $width = 800, int $height = 600, string $name = 'foto.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

function uploadPhoto($item, ?UploadedFile $file = null)
{
    return test()->post(route('checklists.photos.store', [$item->checklist_id, $item]), ['photo' => $file ?? photo()], ['Accept' => 'application/json']);
}

it('uploads a photo for a task, re-encodes it and stores it privately', function () {
    loggedIn($this->hod);

    $response = uploadPhoto($this->item, photo(3200, 2400))->assertCreated()->assertJsonPath('ok', true)->assertJsonStructure(['photo' => ['id', 'url', 'delete_url'], 'progress']);

    $stored = ChecklistItemPhoto::sole();
    expect($stored)->checklist_item_id->toBe($this->item->id)->created_by->toBe($this->hod->id)
        ->and($stored->path)->toStartWith("checklist-photos/{$this->checklist->id}/")->toEndWith('.jpg');
    Storage::disk('local')->assertExists($stored->path);
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($stored->path));
    expect(max($width, $height))->toBe(1600)->and($width / $height)->toEqualWithDelta(4 / 3, 0.01);
    expect($response->json('photo.url'))->toBe(route('checklists.photos.show', [$this->checklist, $stored]));
});

it('allows at most three photos per task and rejects files that are not real photos', function () {
    loggedIn($this->hod);
    foreach (range(1, 3) as $i) {
        uploadPhoto($this->item)->assertCreated();
    }

    uploadPhoto($this->item)->assertUnprocessable()->assertJsonValidationErrors('photo');
    uploadPhoto($this->checklist->items[1], UploadedFile::fake()->createWithContent('palsu.jpg', 'ini bukan gambar'))->assertUnprocessable()->assertJsonValidationErrors('photo');
    uploadPhoto($this->checklist->items[1], UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'))->assertUnprocessable();
    expect(ChecklistItemPhoto::count())->toBe(3);
});

it('only lets people who may edit the checklist add or remove photos', function () {
    loggedIn($this->hod);
    uploadPhoto($this->item);
    $stored = ChecklistItemPhoto::sole();
    $this->post('/logout');

    foreach ([userWithRole(Role::Gm), User::factory()->hodOf($this->hk)->create(), User::factory()->for($this->sari)->create()] as $outsider) {
        loggedIn($outsider);
        uploadPhoto($this->item)->assertForbidden();
        $this->delete(route('checklists.photos.destroy', [$this->checklist, $stored]), [], ['Accept' => 'application/json'])->assertForbidden();
        $this->post('/logout');
    }
    expect(ChecklistItemPhoto::count())->toBe(1);
});

it('locks photos once the checklist is reviewed, except for HR and administrators', function () {
    loggedIn($this->hod);
    uploadPhoto($this->item);
    $stored = ChecklistItemPhoto::sole();
    $this->checklist->update(['status' => ChecklistStatus::Reviewed, 'reviewed_by' => userWithRole(Role::Gm)->id, 'reviewed_at' => now()]);

    uploadPhoto($this->item)->assertForbidden();
    $this->delete(route('checklists.photos.destroy', [$this->checklist, $stored]), [], ['Accept' => 'application/json'])->assertForbidden();
    $this->post('/logout');

    loggedIn(userWithRole(Role::Hr));
    uploadPhoto($this->item)->assertCreated();
    $this->delete(route('checklists.photos.destroy', [$this->checklist, $stored]), [], ['Accept' => 'application/json'])->assertOk();
    expect(ChecklistItemPhoto::count())->toBe(1);
});

it('serves photos only to people who may view the checklist, never publicly', function () {
    loggedIn($this->hod);
    uploadPhoto($this->item);
    $stored = ChecklistItemPhoto::sole();
    $url = route('checklists.photos.show', [$this->checklist, $stored]);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(Storage::disk('local')->path($stored->path))->not->toContain(public_path());
    $this->post('/logout');

    foreach ([Role::Gm, Role::Hr, Role::Admin] as $role) {
        loggedIn(userWithRole($role));
        $this->get($url)->assertOk();
        $this->post('/logout');
    }
    loggedIn(User::factory()->hodOf($this->hk)->create());
    $this->get($url)->assertForbidden();
    $this->post('/logout');
    loggedIn(User::factory()->for($this->sari)->create());
    $this->get($url)->assertForbidden();
    $this->post('/logout');

    // Tanpa login.
    $this->get($url)->assertRedirect('/login');

    // Foto dari checklist lain tidak bisa dibuka lewat alamat checklist ini.
    $other = makeChecklist($this->sari, Position::where('department_id', $this->hk->id)->first(), null, null);
    loggedIn(userWithRole(Role::Admin));
    $this->get(route('checklists.photos.show', [$other, $stored]))->assertNotFound();
    $this->post(route('checklists.photos.store', [$other, $this->item]), ['photo' => photo()], ['Accept' => 'application/json'])->assertNotFound();
});

it('removes a photo, its file, and the files of a deleted checklist', function () {
    loggedIn($this->hod);
    uploadPhoto($this->item);
    uploadPhoto($this->checklist->items[1]);
    $first = ChecklistItemPhoto::first();

    $this->delete(route('checklists.photos.destroy', [$this->checklist, $first]), [], ['Accept' => 'application/json'])->assertOk()->assertJsonStructure(['progress']);
    Storage::disk('local')->assertMissing($first->path);
    $left = ChecklistItemPhoto::sole();
    Storage::disk('local')->assertExists($left->path);
    $this->post('/logout');

    loggedIn(userWithRole(Role::Hr));
    $this->delete(route('checklists.destroy', $this->checklist))->assertRedirect();
    Storage::disk('local')->assertMissing($left->path);
    expect(ChecklistItemPhoto::count())->toBe(0);
});

it('counts missing photos towards completeness according to the setting', function () {
    $settings = app(ChecklistSettings::class);
    $this->checklist->items()->update(['status' => 'done']);
    $this->checklist->items[0]->update(['status' => 'pending', 'note' => 'Menunggu barang']);
    $fresh = fn () => Checklist::find($this->checklist->id);

    expect($settings->photoRequirement())->toBe('optional')->and($fresh()->isComplete())->toBeTrue();

    $settings->put('photo_requirement', 'pending');
    expect($fresh()->missingPhotos())->toBe(1)->and($fresh()->isComplete())->toBeFalse();
    loggedIn($this->hod);
    uploadPhoto($this->item)->assertJsonPath('progress.complete', true)->assertJsonPath('progress.missing_photos', 0);

    $settings->put('photo_requirement', 'all');
    expect($fresh()->missingPhotos())->toBe(1)->and($fresh()->isComplete())->toBeFalse();
    uploadPhoto($this->checklist->items[1]);
    expect($fresh()->isComplete())->toBeTrue();
});

it('does not let an incomplete checklist be reviewed while a required photo is missing', function () {
    app(ChecklistSettings::class)->put('photo_requirement', 'all');
    $this->checklist->items()->update(['status' => 'done']);
    loggedIn(userWithRole(Role::Gm));

    $this->get(route('checklists.show', $this->checklist))->assertSee('Belum lengkap')->assertDontSee('Tandai sudah direview');
    $this->post(route('checklists.review', $this->checklist))->assertForbidden();
});

it('shows photo controls on the work page and thumbnails on the detail page', function () {
    loggedIn($this->hod);
    uploadPhoto($this->item);
    $stored = ChecklistItemPhoto::sole();

    $this->get(route('checklists.fill', $this->checklist))->assertOk()->assertSee('+ Foto')->assertSee('data-photo-requirement="optional"', false)->assertSee('data-photo-max="3"', false)->assertSee(route('checklists.photos.show', [$this->checklist, $stored]));
    $this->get(route('checklists.show', $this->checklist))->assertOk()->assertSee(route('checklists.photos.show', [$this->checklist, $stored]));
});

it('lets only administrators change the photo settings', function () {
    loggedIn(userWithRole(Role::Admin));
    $this->get(route('admin.settings.edit'))->assertOk()->assertSee('Foto bukti')->assertSee('90');

    $this->put(route('admin.settings.update'), ['photo_requirement' => 'pending', 'photo_retention_days' => 30])->assertRedirect();
    expect(Setting::find('photo_requirement')->value)->toBe('pending')->and(Setting::find('photo_retention_days')->value)->toBe('30');
    $this->get(route('admin.settings.edit'))->assertSee('value="30"', false);

    $this->put(route('admin.settings.update'), ['photo_requirement' => 'wajib', 'photo_retention_days' => 30])->assertSessionHasErrors('photo_requirement');
    $this->put(route('admin.settings.update'), ['photo_requirement' => 'all', 'photo_retention_days' => -1])->assertSessionHasErrors('photo_retention_days');
    $this->put(route('admin.settings.update'), ['photo_requirement' => 'all', 'photo_retention_days' => 'abc'])->assertSessionHasErrors('photo_retention_days');
    expect(Setting::find('photo_requirement')->value)->toBe('pending');
    $this->post('/logout');

    foreach ([Role::Hr, Role::Gm, Role::Hod, Role::Staff] as $role) {
        loggedIn(userWithRole($role));
        $this->get(route('admin.settings.edit'))->assertForbidden();
        $this->put(route('admin.settings.update'), ['photo_requirement' => 'all', 'photo_retention_days' => 5])->assertForbidden();
        $this->post('/logout');
    }
    expect(Setting::find('photo_retention_days')->value)->toBe('30');
});

it('deletes photos older than the retention period but keeps the checklist entries', function () {
    loggedIn($this->hod);
    uploadPhoto($this->item);
    uploadPhoto($this->checklist->items[1]);
    [$old, $recent] = ChecklistItemPhoto::orderBy('id')->get();
    $old->forceFill(['created_at' => now()->subDays(91)])->save();
    $recent->forceFill(['created_at' => now()->subDays(89)])->save();
    $this->checklist->items[0]->update(['status' => 'done']);

    $this->artisan('checklist:prune-photos')->assertSuccessful();

    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($recent->path);
    expect(ChecklistItemPhoto::pluck('id')->all())->toBe([$recent->id])->and($this->checklist->items[0]->fresh()->status->value)->toBe('done');

    // Masa simpan diubah Administrator, dan 0 = tidak pernah dihapus.
    app(ChecklistSettings::class)->put('photo_retention_days', 30);
    $this->artisan('checklist:prune-photos')->assertSuccessful();
    expect(ChecklistItemPhoto::count())->toBe(0);

    uploadPhoto($this->item);
    ChecklistItemPhoto::query()->update(['created_at' => now()->subYears(5)]);
    app(ChecklistSettings::class)->put('photo_retention_days', 0);
    $this->artisan('checklist:prune-photos')->assertSuccessful();
    expect(ChecklistItemPhoto::count())->toBe(1);
});

it('does not prune a checklist that only has a photo', function () {
    loggedIn($this->hod);
    uploadPhoto($this->item);
    $this->checklist->forceFill(['updated_at' => now()->subDays(5)])->saveQuietly();

    $this->artisan('checklist:prune-empty')->assertSuccessful();

    expect(Checklist::find($this->checklist->id))->not->toBeNull();
});

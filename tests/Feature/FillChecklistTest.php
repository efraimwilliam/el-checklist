<?php

use App\Enums\ChecklistStatus;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);

    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA', 'sort' => 1]);
    $this->tasks = collect(['Check-in tamu', 'Check-out tamu', 'Handover shift'])
        ->map(fn ($title, $i) => Task::factory()->for($this->gsa)->create(['title' => $title, 'sort' => $i + 1, 'section' => 'Operasional']));
    $this->dm = Position::factory()->for($this->fo)->create(['name' => 'Duty Manager', 'sort' => 2]);
    $this->dmTask = Task::factory()->for($this->dm)->create(['title' => 'Review laporan harian']);
    $this->noTasks = Position::factory()->for($this->fo)->create(['name' => 'Tanpa Tugas']);
    $this->ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    $this->raTask = Task::factory()->for($this->ra)->create(['title' => 'Bersihkan kamar']);

    $this->budi = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso']);
    $this->sari = Employee::factory()->for($this->hk)->create(['name' => 'Sari Dewi']);
    $this->hod = User::factory()->hodOf($this->fo)->create();
    $this->today = CarbonImmutable::today();
});

/** Data "Mulai checklist" untuk GSA Budi hari ini. */
function startData(array $override = []): array
{
    return ['position' => test()->gsa->id, 'employee' => test()->budi->id, 'date' => test()->today->toDateString(), ...$override];
}

function startedChecklist(): Checklist
{
    test()->post(route('fill.start'), startData())->assertRedirect();

    return Checklist::sole();
}

it('shows the HOD only their own department in the selector', function () {
    Employee::factory()->for($this->fo)->create(['name' => 'Sudah Keluar', 'is_active' => false]);
    loggedIn($this->hod);
    everyoneWorks();

    $this->get(route('fill.assign'))->assertOk()
        ->assertSee('GSA (3 tugas)')->assertSee('Duty Manager (1 tugas)')
        ->assertDontSee('Tanpa Tugas')->assertDontSee('Room Attendant')
        ->assertSee('Budi Santoso')->assertDontSee('Sari Dewi')->assertDontSee('Sudah Keluar')
        ->assertDontSee('Dicek oleh'); // pemeriksa = akun yang login, bukan isian
});

it('creates an empty checklist when started, and opens the same one when started again', function () {
    loggedIn($this->hod);

    $checklist = startedChecklist();
    $this->post(route('fill.start'), startData())->assertRedirect(route('checklists.fill', $checklist));

    expect(Checklist::count())->toBe(1)
        ->and($checklist)->employee_id->toBe($this->budi->id)->department_id->toBe($this->fo->id)->position_name->toBe('GSA')
        ->checked_by->toBe($this->hod->name())->filled_by->toBe($this->hod->id)
        ->and($checklist->status)->toBe(ChecklistStatus::Saved)
        ->and($checklist->items->pluck('title')->all())->toBe(['Check-in tamu', 'Check-out tamu', 'Handover shift'])
        ->and($checklist->items->pluck('status')->filter()->count())->toBe(0)
        ->and($checklist->isComplete())->toBeFalse();
});

it('shows the work page with cards, the list toggle and the tasks', function () {
    loggedIn($this->hod);
    $checklist = startedChecklist();

    $this->get(route('checklists.fill', $checklist))->assertOk()
        ->assertSee('Check-in tamu')->assertSee('Handover shift')->assertSee('data-view="card"', false)
        ->assertSee('cl-actions', false)->assertSee('data-note-toggle', false)->assertSee('data-deck', false)->assertSee('Geser kiri atau kanan')->assertSee('Lihat semua tugas')
        ->assertSee('Tampilan kartu')->assertSee('Tampilan daftar')->assertSee('Lihat semua tugas')->assertDontSee('Kosongkan')->assertDontSee('Sisa tugas')->assertDontSee('Lanjut otomatis')->assertSee('Tugas tambahan')->assertSee('Tersimpan otomatis')
        ->assertDontSee('Simpan checklist');
});

it('saves each task as soon as it is chosen, and reports progress', function () {
    loggedIn($this->hod);
    $checklist = startedChecklist();
    [$a, $b, $c] = $checklist->items;
    $url = fn ($item) => route('checklists.items.update', [$checklist, $item]);

    $this->patchJson($url($a), ['status' => 'done'])->assertOk()
        ->assertJsonPath('progress.filled', 1)->assertJsonPath('progress.total', 3)->assertJsonPath('progress.complete', false)->assertJsonPath('items.'.$a->id.'.status', 'done');
    expect($a->fresh()->status->value)->toBe('done')->and($b->fresh()->status)->toBeNull();

    $this->patchJson($url($b), ['status' => 'ongoing', 'note' => ' Menunggu tamu '])->assertOk();
    expect($b->fresh())->status->value->toBe('ongoing')->note->toBe('Menunggu tamu');

    // Pending tanpa alasan tetap tersimpan, hanya membuat checklist belum lengkap.
    $this->patchJson($url($c), ['status' => 'pending'])->assertOk()->assertJsonPath('progress.complete', false)->assertJsonPath('progress.missing_reasons', 1);
    $this->patchJson($url($c), ['status' => 'pending', 'note' => 'Menunggu barang dari store'])->assertOk()
        ->assertJsonPath('progress.complete', true)->assertJsonPath('progress.score', 33);

    // Status bisa dikosongkan lagi.
    $this->patchJson($url($a), ['status' => null])->assertOk()->assertJsonPath('progress.filled', 2);
    expect($a->fresh()->status)->toBeNull()->and($checklist->fresh()->filled_by)->toBe($this->hod->id);
});

it('rejects invalid autosave requests and items of another checklist', function () {
    loggedIn($this->hod);
    $checklist = startedChecklist();
    $other = makeChecklist($this->budi, $this->dm, $this->today, null);

    $this->patchJson(route('checklists.items.update', [$checklist, $checklist->items[0]]), ['status' => 'selesai'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->patchJson(route('checklists.items.update', [$checklist, $other->items[0]]), ['status' => 'done'])->assertNotFound();
    expect($other->items[0]->fresh()->status)->toBeNull();
});

it('lets a chosen status be taken back, and has no clear-all or "rest = Done" route', function () {
    loggedIn($this->hod);
    $checklist = startedChecklist();
    $checklist->items[0]->update(['status' => 'done']);

    $this->patchJson(route('checklists.items.update', [$checklist, $checklist->items[0]]), ['status' => null, 'note' => null])
        ->assertOk()->assertJsonPath('progress.filled', 0);

    expect($checklist->items[0]->fresh()->status)->toBeNull();
    expect(Route::has('checklists.rest-done'))->toBeFalse()->and(Route::has('checklists.clear'))->toBeFalse();
});

it('saves the general note, who checked and the extra tasks, changing only what is sent', function () {
    loggedIn($this->hod);
    $checklist = startedChecklist();

    $this->patchJson(route('checklists.meta', $checklist), ['note' => 'Lobby ramai', 'extras' => [
        ['title' => 'Membantu banquet', 'status' => 'done', 'note' => 'Sampai malam'],
        ['title' => '  ', 'status' => 'pending'],
        ['title' => 'Antar tamu VIP', 'status' => 'ongoing'],
    ]])->assertOk();
    $checklist->refresh();
    expect($checklist)->note->toBe('Lobby ramai')->checked_by->toBe($this->hod->name()) // tidak dikirim = tidak berubah
        ->and($checklist->extraTasks->pluck('title')->all())->toBe(['Membantu banquet', 'Antar tamu VIP'])
        ->and($checklist->extraTasks[0]->note)->toBe('Sampai malam');

    $this->patchJson(route('checklists.meta', $checklist), ['note' => 'Diubah', 'checked_by' => 'Dipalsukan'])->assertOk(); // pemeriksa tidak bisa diubah lewat simpan otomatis
    expect($checklist->fresh())->note->toBe('Diubah')->checked_by->toBe($this->hod->name())->and($checklist->extraTasks()->count())->toBe(2);

    $this->patchJson(route('checklists.meta', $checklist), ['extras' => []])->assertOk();
    expect($checklist->extraTasks()->count())->toBe(0);

    // Tugas tambahan tidak ikut skor.
    $checklist->items()->update(['status' => 'done']);
    expect($checklist->fresh()->load('items')->score())->toBe(100);
});

it('allows past dates and dates up to 60 days ahead for planning, but not further when starting', function () {
    loggedIn($this->hod);

    $this->post(route('fill.start'), startData(['date' => $this->today->addDays(61)->toDateString()]))->assertSessionHasErrors('date');
    expect(Checklist::count())->toBe(0);

    $this->post(route('fill.start'), startData(['date' => $this->today->addDay()->toDateString()]))->assertRedirect();

    $this->post(route('fill.start'), startData(['date' => $this->today->subDay()->toDateString()]))->assertRedirect();
    expect(Checklist::whereDate('date', $this->today->subDay())->exists())->toBeTrue();
});

it('records the logged-in account as the checker and ignores a forged value', function () {
    loggedIn($this->hod);

    $this->post(route('fill.start'), startData(['checker' => 'Dipalsukan']))->assertRedirect();

    expect(Checklist::sole()->checked_by)->toBe($this->hod->name());
});

it('keeps one checklist per position for the same staff and date', function () {
    loggedIn($this->hod);
    $this->post(route('fill.start'), startData());
    $this->post(route('fill.start'), startData(['position' => $this->dm->id]));

    expect(Checklist::count())->toBe(2);
});

it('shows the team board for the day and offers to continue unfinished checklists of earlier days', function () {
    loggedIn($this->hod);
    everyoneWorks();
    $checklist = startedChecklist();
    $checklist->items[0]->update(['status' => 'done']);

    // Hari ini: di papan tim, tidak diulang di "Lanjutkan checklist".
    $this->get(route('fill.create'))->assertSee('Front Office')->assertSee('Budi Santoso')->assertSee('Belum lengkap')->assertSee('1 / 3 terisi')
        ->assertSee('Lanjutkan')->assertSee(route('checklists.fill', $checklist))->assertDontSee('Lanjutkan checklist');

    // Kemarin: papan hari ini kosong untuk staff itu, checklist lamanya muncul di "Lanjutkan checklist".
    $checklist->update(['date' => today()->subDay()]);
    $this->get(route('fill.create'))->assertSee('Lanjutkan checklist')->assertSee('1 / 3 terisi')->assertSee('Belum dibuat');

    $checklist->items()->update(['status' => 'done']);
    $this->get(route('fill.create'))->assertDontSee('Lanjutkan checklist');
});

it('does not let a HOD fill for another department or mix departments', function () {
    loggedIn($this->hod);

    $this->post(route('fill.start'), startData(['employee' => $this->sari->id, 'position' => $this->ra->id]))->assertForbidden();
    $this->post(route('fill.start'), startData(['position' => $this->ra->id]))->assertForbidden(); // jabatan HK untuk staff FO
    $this->budi->update(['is_active' => false]);
    $this->post(route('fill.start'), startData())->assertForbidden(); // staff nonaktif
    expect(Checklist::count())->toBe(0);
});

it('lets staff with an account work only on their own checklist', function () {
    $staff = User::factory()->for($this->budi)->create(['position_id' => $this->gsa->id]);
    loggedIn($staff);
    everyoneWorks();

    $this->get(route('checklists.today'))->assertRedirect(route('fill.create'));
    $this->get(route('fill.create'))->assertOk()->assertSee('Checklist saya')->assertSee('Isi checklist')->assertDontSee('Dicek oleh');

    $this->post(route('fill.start'), startData())->assertRedirect();
    $checklist = Checklist::sole();
    expect($checklist->checked_by)->toBeNull()->and($checklist->filled_by)->toBe($staff->id);
    $this->patchJson(route('checklists.items.update', [$checklist, $checklist->items[0]]), ['status' => 'done'])->assertOk();

    $other = Employee::factory()->for($this->fo)->create();
    $this->post(route('fill.start'), startData(['employee' => $other->id]))->assertForbidden();
    $this->post(route('fill.start'), startData(['position' => $this->dm->id]))->assertForbidden();
    expect(Checklist::count())->toBe(1);
});

it('tells staff without a checklist position to contact HR', function () {
    loggedIn(User::factory()->for($this->budi)->create(['position_id' => null]));
    everyoneWorks();

    $this->get(route('fill.create'))->assertOk()->assertSee('Jabatan checklist Anda belum diatur');
});

it('does not let the GM fill checklists', function () {
    loggedIn(userWithRole(Role::Gm));
    everyoneWorks();

    $this->get(route('fill.create'))->assertForbidden();
    $this->post(route('fill.start'), startData())->assertForbidden();
});

it('lets HR and administrators work in any department', function (Role $role) {
    loggedIn(userWithRole($role));
    everyoneWorks();

    $this->get(route('fill.assign'))->assertOk()->assertSee('Front Office')->assertSee('Housekeeping');
    $this->get(route('fill.assign', ['department' => $this->hk->id]))->assertSee('Room Attendant (1 tugas)')->assertSee('Sari Dewi')->assertDontSee('Budi Santoso');
    $this->post(route('fill.start'), startData(['employee' => $this->sari->id, 'position' => $this->ra->id]))->assertRedirect();
    expect(Checklist::sole()->department_id)->toBe($this->hk->id);
})->with([Role::Hr, Role::Admin]);

it('uses the tasks chosen for the staff member, but only for new checklists', function () {
    $old = makeChecklist($this->budi, $this->gsa, $this->today->subDay());
    $this->budi->assignedTasks()->sync([$this->tasks[2]->id, $this->dmTask->id]);
    $this->budi->update(['custom_tasks' => true]);
    loggedIn($this->hod);

    $this->post(route('fill.start'), startData())->assertRedirect();

    // Tugas jabatan yang dipilih (GSA) lebih dulu, lalu tugas jabatan lain.
    expect(Checklist::latest('id')->first()->items->pluck('title')->all())->toBe(['Handover shift', 'Review laporan harian'])
        ->and(Checklist::find($old->id)->items->count())->toBe(3);
});

it('locks a reviewed checklist for the HOD: no autosave, and the work page sends them to the result', function () {
    loggedIn($this->hod);
    $checklist = startedChecklist();
    $checklist->items()->update(['status' => 'done']);
    $checklist->update(['status' => ChecklistStatus::Reviewed, 'reviewed_by' => userWithRole(Role::Gm)->id, 'reviewed_at' => now()]);

    $this->patchJson(route('checklists.items.update', [$checklist, $checklist->items[0]]), ['status' => 'pending'])->assertForbidden();
    $this->patchJson(route('checklists.meta', $checklist), ['note' => 'x'])->assertForbidden();
    $this->get(route('checklists.fill', $checklist))->assertRedirect(route('checklists.show', $checklist));
    $this->post(route('fill.start'), startData())->assertRedirect(route('checklists.show', $checklist));
    expect($checklist->items[0]->fresh()->status->value)->toBe('done');

    $this->post('/logout');
    loggedIn(userWithRole(Role::Hr));
    $this->patchJson(route('checklists.items.update', [$checklist, $checklist->items[0]]), ['status' => 'pending', 'note' => 'koreksi HR'])->assertOk();
});

it('answers an expired session with JSON so autosave can tell the user', function () {
    loggedIn($this->hod);
    $checklist = startedChecklist();

    $this->travel(config('session.absolute_lifetime') + 1)->minutes();

    $this->patchJson(route('checklists.items.update', [$checklist, $checklist->items[0]]), ['status' => 'done'])->assertUnauthorized()->assertJsonStructure(['message']);
});

it('prunes abandoned empty checklists but keeps the ones with any entry or recent ones', function () {
    $admin = User::factory()->role(Role::Admin)->create();
    $make = fn (Employee $e, Position $p, int $days, ?callable $touch = null) => tap(makeChecklist($e, $p, $this->today->subDays($days + 5), null, $admin), function ($c) use ($days, $touch) {
        $touch && $touch($c);
        $c->forceFill(['updated_at' => now()->subDays($days)])->saveQuietly();
    });
    $empty = $make($this->budi, $this->gsa, 3);
    $answered = $make($this->budi, $this->dm, 3, fn ($c) => $c->items[0]->update(['status' => 'done']));
    $withNote = $make($this->sari, $this->ra, 3, fn ($c) => $c->update(['note' => 'catatan']));
    $recent = $make(Employee::factory()->for($this->fo)->create(), $this->gsa, 0);

    $this->artisan('checklist:prune-empty')->assertSuccessful();

    expect(Checklist::find($empty->id))->toBeNull()
        ->and(Checklist::find($answered->id))->not->toBeNull()->and(Checklist::find($withNote->id))->not->toBeNull()->and(Checklist::find($recent->id))->not->toBeNull();
});

it('hides the finish button until the provisional score is 100%', function () {
    loggedIn($this->hod);
    $checklist = startedChecklist();
    $finish = '<a href="'.route('checklists.show', $checklist).'" data-finish class="btn-primary ';

    $this->get(route('checklists.fill', $checklist))->assertSee($finish.'hidden"', false)->assertDontSee('Aktif saat skor 100%');

    $checklist->items()->update(['status' => 'done']);
    $this->get(route('checklists.fill', $checklist))->assertSee($finish.'"', false)->assertDontSee($finish.'hidden"', false);

    // Satu tugas Ongoing: skor di bawah 100%, tombol disembunyikan lagi. "Lihat hasil" di atas halaman tetap ada.
    $checklist->items[0]->update(['status' => 'ongoing']);
    $this->get(route('checklists.fill', $checklist))->assertSee($finish.'hidden"', false)->assertSee('Lihat hasil');
});

it('offers the print button to managers but not to staff', function () {
    loggedIn($this->hod);
    $this->get(route('checklists.show', startedChecklist()))->assertSee('Cetak');
});

it('does not offer the print button to staff', function () {
    loggedIn(User::factory()->for($this->budi)->create(['role' => Role::Staff, 'position_id' => $this->gsa->id]));
    $this->post(route('fill.start'), startData())->assertRedirect();

    $this->get(route('checklists.show', Checklist::sole()))->assertOk()->assertDontSee('Cetak');
});

function everyoneWorks(): void
{
    fakeSchedules([[test()->budi, 'work', 'P'], [test()->sari, 'work', 'M']]);
}

it('marks staff without a schedule in Absensi as disabled on the team board', function () {
    loggedIn($this->hod);
    Employee::factory()->for($this->fo)->create(['name' => 'Ani Kosong', 'checklist_position_id' => $this->gsa->id]);
    fakeSchedules([[$this->budi, 'work', 'P']]);

    $this->get(route('fill.assign'))->assertOk()
        ->assertSee('Ani Kosong')->assertSee('Jadwal belum diisi')->assertSee('Tidak ada jadwal di Absensi untuk tanggal ini')
        ->assertSee('disabled class="btn-secondary !px-4 !py-1.5" title="Jadwal belum diisi di Absensi"', false);
    expect(Checklist::count())->toBe(0);
});

it('does not mark anyone when the Absensi schedule cannot be read', function () {
    loggedIn($this->hod);
    Employee::factory()->for($this->fo)->create(['name' => 'Ani Kosong', 'checklist_position_id' => $this->gsa->id]);
    fakeAttendance([SCHEDULES_URL => Http::response(['message' => 'down'], 500)]);

    $this->get(route('fill.assign'))->assertOk()
        ->assertSee('Jadwal Absensi belum bisa dibaca')->assertSee('Ani Kosong')->assertDontSee('Jadwal belum diisi');
});


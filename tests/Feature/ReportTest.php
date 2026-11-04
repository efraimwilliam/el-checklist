<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ReportNote;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Carbon\CarbonImmutable;

/** Login untuk Laporan: Absensi dipalsukan (jadwal kosong), tes yang butuh jadwal memalsukannya lagi setelah ini. */
function reportLogin(User $user): void
{
    loggedIn($user);
    fakeSchedules([]);
}

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    Task::factory()->for($this->gsa)->create(['title' => 'Check-in tamu']);
    Task::factory()->for($this->gsa)->create(['title' => 'Cek minibar kamar']);
    $this->budi = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso']);
    $this->ani = Employee::factory()->for($this->fo)->create(['name' => 'Ani Kosong']);
    $this->sari = Employee::factory()->for($this->hk)->create(['name' => 'Sari Dewi']);
    $this->hod = User::factory()->hodOf($this->fo)->create();
    $this->today = CarbonImmutable::today();
    $this->range = ['from' => $this->today->subDays(6)->toDateString(), 'to' => $this->today->toDateString()];
});

it('is closed to staff and shows managers their own departments only', function () {
    reportLogin(User::factory()->role(Role::Staff)->create());
    $this->get(route('laporan.index'))->assertForbidden();
    $this->post('/logout');

    reportLogin($this->hod);
    $this->get(route('laporan.index', $this->range))->assertOk()->assertSee('Front Office')->assertDontSee('Housekeeping');
});

it('reports pending reasons, the most used pending tasks and staff without a checklist', function () {
    $checklist = makeChecklist($this->budi, $this->gsa, $this->today, null);
    $checklist->items()->orderBy('sort')->get()->each(fn ($item, $i) => $item->update(
        $i === 0 ? ['status' => 'pending', 'note' => 'Barang habis di store'] : ['status' => 'done']
    ));

    reportLogin($this->hod);
    fakeSchedules([[$this->budi, 'work', 'P'], [$this->ani, 'work', 'P']]);
    $this->get(route('laporan.index', $this->range))->assertOk()
        ->assertSee('Barang habis di store')->assertSee('Budi Santoso')
        ->assertSee('Ani Kosong')->assertSee('2 staff');
});

it('adds and deletes notes for the period, and only the author or HR may delete them', function () {
    reportLogin($this->hod);
    $this->post(route('laporan.notes.store'), $this->range + ['department_id' => $this->fo->id, 'body' => 'Pembelian minibar dijadwalkan Senin'])
        ->assertRedirect();
    $note = ReportNote::sole();
    expect($note)->department_id->toBe($this->fo->id)->created_by->toBe($this->hod->id)->body->toBe('Pembelian minibar dijadwalkan Senin');
    $this->get(route('laporan.index', $this->range))->assertSee('Pembelian minibar dijadwalkan Senin');

    $this->post(route('laporan.notes.store'), $this->range + ['department_id' => $this->hk->id, 'body' => 'Bukan departemen saya'])->assertForbidden();
    $this->post(route('laporan.notes.store'), $this->range + ['body' => 'Tanpa departemen'])->assertSessionHasErrors('department_id');

    $this->delete(route('laporan.notes.destroy', $note))->assertRedirect();
    expect(ReportNote::count())->toBe(0);

    ReportNote::create(['department_id' => $this->fo->id, 'period_from' => $this->range['from'], 'period_to' => $this->range['to'], 'body' => 'Catatan orang lain', 'created_by' => userWithRole(Role::Gm)->id]);
    $other = ReportNote::sole();
    $this->delete(route('laporan.notes.destroy', $other))->assertForbidden();
    $this->post('/logout');
    reportLogin(userWithRole(Role::Hr));
    $this->delete(route('laporan.notes.destroy', $other))->assertRedirect();
});

it('exports an Excel workbook of the range, limited to the departments the user may see', function () {
    makeChecklist($this->budi, $this->gsa, $this->today, 'done');
    makeChecklist($this->sari, Position::factory()->for($this->hk)->create(), $this->today, 'done');
    reportLogin($this->hod);

    $response = $this->get(route('laporan.export', $this->range))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $path = tempnam(sys_get_temp_dir(), 'laporan').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $book = IOFactory::load($path);
    expect($book->getSheetNames())->toBe(['Ringkasan', 'Checklist']);
    $names = collect($book->getSheetByName('Checklist')->toArray())->flatten()->implode(' ');
    expect($names)->toContain('Budi Santoso')->not->toContain('Sari Dewi');
    unlink($path);
});

it('shows the new report sections and compares with the previous period', function () {
    makeChecklist($this->budi, $this->gsa, $this->today, 'done');
    reportLogin($this->hod);

    $this->get(route('laporan.index', $this->range))->assertOk()
        ->assertSee('Skor per jabatan')->assertSee('Skor per staff')->assertSee('Tugas sering dilewati')
        ->assertSee('Kebiasaan pengisian')->assertSee('Pola per hari')->assertSee('Unduh Excel')->assertSee('dari periode sebelumnya');
});

it('writes the weekly summary file', function () {
    Storage::fake('local');
    userWithRole(Role::Admin);
    makeChecklist($this->budi, $this->gsa, CarbonImmutable::yesterday(), 'done');
    fakeSchedules([]);

    $this->artisan('laporan:mingguan')->assertSuccessful();
    Storage::disk('local')->assertExists('laporan/mingguan-'.CarbonImmutable::yesterday()->toDateString().'.txt');
});


it('exports the Riwayat list to Excel with the same filters as the page', function () {
    makeChecklist($this->budi, $this->gsa, $this->today, 'done');
    makeChecklist($this->sari, Position::factory()->for($this->hk)->create(), $this->today, 'done');
    reportLogin($this->hod);

    $response = $this->get(route('checklists.export', $this->range))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $path = tempnam(sys_get_temp_dir(), 'riwayat').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $book = IOFactory::load($path);
    expect($book->getSheetNames())->toBe(['Riwayat']);
    $text = collect($book->getActiveSheet()->toArray())->flatten()->implode(' ');
    expect($text)->toContain('Budi Santoso')->not->toContain('Sari Dewi');
    unlink($path);
});

it('ranks problem tasks, lists chronic pending and explores task data within allowed departments', function () {
    foreach (range(0, 3) as $back) {
        $checklist = makeChecklist($this->budi, $this->gsa, $this->today->subDays($back), null);
        $checklist->items()->orderBy('sort')->get()->each(fn ($item, $i) => $item->update(
            $i === 0 ? ['status' => 'pending', 'note' => "AC rusak {$back}"] : ['status' => 'done']
        ));
    }
    $hkPosition = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    Task::factory()->for($hkPosition)->create(['title' => 'Bersihkan balkon']);
    makeChecklist($this->sari, $hkPosition, $this->today, 'pending');

    reportLogin($this->hod);
    $this->get(route('laporan.issues', $this->range))->assertOk()
        ->assertSee('Pending berulang')->assertSee('Budi Santoso')->assertSee('AC rusak 0')
        ->assertDontSee('Bersihkan balkon')->assertDontSee('Sari Dewi');

    $first = Task::where('title', 'Check-in tamu')->value('title');
    $this->get(route('laporan.task', $this->range + ['department_id' => $this->fo->id, 'title' => $first]))
        ->assertOk()->assertSee('Budi Santoso')->assertSee('AC rusak 3');
    $this->get(route('laporan.task', $this->range + ['department_id' => $this->hk->id, 'title' => 'Bersihkan balkon']))->assertNotFound();

    $this->get(route('laporan.data', $this->range + ['status' => 'pending', 'q' => 'AC rusak']))->assertOk()
        ->assertSee('4 isian tugas')->assertSee('AC rusak 2');
    // departemen lain diabaikan, tetap hanya Front Office
    $this->get(route('laporan.data', $this->range + ['department_id' => $this->hk->id]))->assertOk()->assertDontSee('Sari Dewi');

    $response = $this->get(route('laporan.data.export', $this->range + ['status' => 'pending']))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $path = tempnam(sys_get_temp_dir(), 'data').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $text = collect(IOFactory::load($path)->getActiveSheet()->toArray())->flatten()->implode(' ');
    expect($text)->toContain('AC rusak 1')->not->toContain('Sari Dewi');
    unlink($path);
});

it('closes the data tabs to staff', function () {
    reportLogin(User::factory()->role(Role::Staff)->create());
    $this->get(route('laporan.issues'))->assertForbidden();
    $this->get(route('laporan.data'))->assertForbidden();
    $this->get(route('laporan.data.export'))->assertForbidden();
});

it('lets a HOD record follow-up on a pending item and auto-resolves it when done later', function () {
    $old = makeChecklist($this->budi, $this->gsa, $this->today->subDays(3), 'pending');
    $item = $old->items()->orderBy('sort')->first();
    $later = makeChecklist($this->budi, $this->gsa, $this->today->subDay(), 'done');

    reportLogin($this->hod);
    $page = $this->get(route('laporan.issues', $this->range))->assertOk();
    $page->assertSee('Masalah terbuka');
    expect($page->getContent())->not->toContain(route('laporan.followup', $item->id));

    $later->items()->where('title', $item->title)->update(['status' => 'pending']);
    $this->get(route('laporan.issues', $this->range))->assertSee(route('laporan.followup', $item->id));

    $this->post(route('laporan.followup', $item->id), ['status' => 'handling', 'note' => 'Teknisi dipanggil'])->assertRedirect();
    expect(\App\Models\IssueFollowup::where('checklist_item_id', $item->id)->value('status'))->toBe('handling');
    $this->get(route('laporan.issues', $this->range))->assertSee('Teknisi dipanggil')->assertSee('Ditangani');

    $this->post(route('laporan.followup', $item->id), ['status' => 'bogus'])->assertSessionHasErrors('status');
    $ra = Position::factory()->for($this->hk)->create(['name' => 'RA']);
    Task::factory()->for($ra)->create(['title' => 'Sapu lorong']);
    $hkItem = makeChecklist($this->sari, $ra, $this->today, 'pending')->items()->first();
    $this->post(route('laporan.followup', $hkItem->id), ['status' => 'resolved'])->assertForbidden();

    $this->post('/logout');
    reportLogin(User::factory()->role(Role::Gm)->create());
    $this->post(route('laporan.followup', $item->id), ['status' => 'resolved'])->assertForbidden();
});

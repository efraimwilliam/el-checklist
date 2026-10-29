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
    loggedIn(User::factory()->role(Role::Staff)->create());
    $this->get(route('laporan.index'))->assertForbidden();
    $this->post('/logout');

    loggedIn($this->hod);
    $this->get(route('laporan.index', $this->range))->assertOk()->assertSee('Front Office')->assertDontSee('Housekeeping');
});

it('reports pending reasons, the most used pending tasks and staff without a checklist', function () {
    $checklist = makeChecklist($this->budi, $this->gsa, $this->today, null);
    $checklist->items()->orderBy('sort')->get()->each(fn ($item, $i) => $item->update(
        $i === 0 ? ['status' => 'pending', 'note' => 'Barang habis di store'] : ['status' => 'done']
    ));

    loggedIn($this->hod);
    $this->get(route('laporan.index', $this->range))->assertOk()
        ->assertSee('Barang habis di store')->assertSee('Budi Santoso')
        ->assertSee('Ani Kosong')->assertSee('1 staff');
});

it('adds and deletes notes for the period, and only the author or HR may delete them', function () {
    loggedIn($this->hod);
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
    loggedIn(userWithRole(Role::Hr));
    $this->delete(route('laporan.notes.destroy', $other))->assertRedirect();
});

it('exports an Excel workbook of the range, limited to the departments the user may see', function () {
    makeChecklist($this->budi, $this->gsa, $this->today, 'done');
    makeChecklist($this->sari, Position::factory()->for($this->hk)->create(), $this->today, 'done');
    loggedIn($this->hod);

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
    loggedIn($this->hod);

    $this->get(route('laporan.index', $this->range))->assertOk()
        ->assertSee('Skor per jabatan')->assertSee('Skor per staff')->assertSee('Tugas sering dilewati')
        ->assertSee('Kebiasaan pengisian')->assertSee('Pola per hari')->assertSee('Unduh Excel')->assertSee('dari periode sebelumnya');
});

it('writes the weekly summary file', function () {
    Storage::fake('local');
    userWithRole(Role::Admin);
    makeChecklist($this->budi, $this->gsa, CarbonImmutable::yesterday(), 'done');

    $this->artisan('laporan:mingguan')->assertSuccessful();
    Storage::disk('local')->assertExists('laporan/mingguan-'.CarbonImmutable::yesterday()->toDateString().'.txt');
});


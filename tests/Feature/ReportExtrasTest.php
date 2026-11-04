<?php

use App\Enums\Role;
use App\Models\ChecklistItemParameter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $this->other = Department::factory()->create(['name' => 'Kitchen']);
    $this->position = Position::factory()->for($this->eng)->create(['name' => 'Technician']);
    $this->task = Task::factory()->for($this->position)->create(['title' => 'Cek genset']);
    $this->emp = Employee::factory()->for($this->eng)->create(['name' => 'Wayan Teknik']);
    $this->today = CarbonImmutable::today();
    $this->range = ['from' => $this->today->subDays(6)->toDateString(), 'to' => $this->today->toDateString()];
});

function addReading($test, string $date, string $name, string $type, string $value, array $rule = []): void
{
    $checklist = makeChecklist($test->emp, $test->position, CarbonImmutable::parse($date), 'done');
    $item = $checklist->items->first();
    ChecklistItemParameter::create(['checklist_item_id' => $item->id, 'sort' => 2, 'name' => $name, 'type' => $type, 'value' => $value] + $rule);
}

it('summarises numeric readings, meter usage and out-of-range values', function () {
    addReading($this, $this->today->subDays(2)->toDateString(), 'Voltase', 'number', '30', ['unit' => 'V', 'min_value' => 22, 'max_value' => 28]);
    addReading($this, $this->today->subDay()->toDateString(), 'Voltase', 'number', '24,5', ['unit' => 'V', 'min_value' => 22, 'max_value' => 28]);

    loggedIn(User::factory()->role(Role::Admin)->create());
    fakeSchedules([]);
    $this->get(route('laporan.parameters', $this->range))->assertOk()
        ->assertSee('Cek genset')->assertSee('Voltase')->assertSee('30 V')->assertSee('maks 28');
});

it('calculates meter usage between first and last reading', function () {
    addReading($this, $this->today->subDays(3)->toDateString(), 'KWH', 'meter', '1000', ['unit' => 'kWh']);
    addReading($this, $this->today->toDateString(), 'KWH', 'meter', '1250', ['unit' => 'kWh']);

    $rows = app(\App\Reports\ParameterReport::class)->summary([$this->eng->id], array_values($this->range));
    expect($rows)->toHaveCount(1)->and($rows[0]['usage'])->toBe(250.0)->and($rows[0]['meter'])->toBeTrue();
});

it('stores daily snapshots idempotently and shows monthly totals', function () {
    makeChecklist($this->emp, $this->position, $this->today, 'pending');

    $this->artisan('laporan:snapshot')->assertSuccessful();
    $this->artisan('laporan:snapshot')->assertSuccessful();
    expect(DB::table('report_snapshots')->count())->toBe(1)
        ->and((int) DB::table('report_snapshots')->value('pending'))->toBe(1);

    // checklist dihapus, angka bulanan tetap
    \App\Models\Checklist::query()->delete();
    $hod = User::factory()->hodOf($this->eng)->create();
    loggedIn($hod);
    fakeSchedules([]);
    $this->get(route('laporan.monthly'))->assertOk()->assertSee($this->today->translatedFormat('F Y'))->assertSee('Pending');

    $other = Position::factory()->for($this->other)->create(['name' => 'Cook']);
    expect(app(\App\Reports\MonthlyReport::class)->months([$this->other->id]))->toBe([]);
});

it('keeps the new report tabs closed to staff', function () {
    loggedIn(User::factory()->role(Role::Staff)->create());
    $this->get(route('laporan.monthly'))->assertForbidden();
    $this->get(route('laporan.parameters'))->assertForbidden();
});

it('lists every report page in the Laporan menu of the header', function () {
    loggedIn(User::factory()->hodOf($this->eng)->create());
    fakeSchedules([]);
    $this->get(route('laporan.index'))->assertOk()
        ->assertSee(route('laporan.index'), false)->assertSee(route('laporan.issues'), false)
        ->assertSee(route('laporan.parameters'), false)->assertSee(route('laporan.monthly'), false)
        ->assertSee(route('laporan.data'), false);
});

it('searches tasks, reasons and staff across the problem lists and explains when nothing matches', function () {
    $checklist = makeChecklist($this->emp, $this->position, $this->today, null);
    $checklist->items()->orderBy('sort')->get()->each(fn ($item, $i) => $item->update(
        $i === 0 ? ['status' => 'pending', 'note' => 'Genset mati total'] : ['status' => 'done']
    ));
    $title = $checklist->items()->orderBy('sort')->first()->title;

    loggedIn(User::factory()->role(Role::Admin)->create());
    fakeSchedules([]);

    // Cocok dengan alasan, nama staff, dan judul tugas.
    foreach (['Genset', 'Wayan', mb_substr($title, 0, 6)] as $keyword) {
        $this->get(route('laporan.issues', $this->range + ['q' => $keyword]))->assertOk()
            ->assertDontSee('Tidak ditemukan')->assertSee('Genset mati total');
    }

    // Tidak ada yang cocok: satu penjelasan yang jelas, bukan daftar kosong berulang.
    $this->get(route('laporan.issues', $this->range + ['q' => 'zzzxqv']))->assertOk()
        ->assertSee('Tidak ditemukan')->assertSee('zzzxqv')->assertDontSee('role="tablist"', false)->assertDontSee('Atur ulang');
});

it('shows the track record of earlier readings under each parameter summary, newest first and capped at ten', function () {
    $range = ['from' => $this->today->subDays(29)->toDateString(), 'to' => $this->today->toDateString()];
    foreach (range(11, 1) as $back) {
        addReading($this, $this->today->subDays($back)->toDateString(), 'Voltase', 'number', (string) (20 + $back), ['unit' => 'V', 'min_value' => 22, 'max_value' => 28]);
    }
    addReading($this, $this->today->toDateString(), 'Voltase', 'number', '30', ['unit' => 'V', 'min_value' => 22, 'max_value' => 28]);

    $rows = app(\App\Reports\ParameterReport::class)->summary([$this->eng->id], array_values($range));
    $history = $rows[0]['history'];
    expect($rows)->toHaveCount(1)->and($rows[0]['readings'])->toBe(12)->and($history)->toHaveCount(10)
        ->and($history[0]['value'])->toBe(30.0)->and($history[0]['out'])->toBeTrue()
        ->and(array_column($history, 'date'))->toBe(collect(array_column($history, 'date'))->sortDesc()->values()->all());

    loggedIn(User::factory()->role(Role::Admin)->create());
    fakeSchedules([]);
    $this->get(route('laporan.parameters', $range))->assertOk()
        ->assertSee('Track record')->assertSee('data-history-row', false)->assertSee('Wayan Teknik')->assertSee('30 V')->assertSee('pembacaan terakhir');
});

it('explains in plain words what the parameter summary table shows', function () {
    addReading($this, $this->today->toDateString(), 'Voltase', 'number', '24', ['unit' => 'V', 'min_value' => 22, 'max_value' => 28]);

    loggedIn(User::factory()->role(Role::Admin)->create());
    fakeSchedules([]);
    $this->get(route('laporan.parameters', $this->range))->assertOk()
        ->assertSee('Ringkasan nilai angka dan stand meter')->assertSee('Satu baris = satu parameter pada satu tugas.')
        ->assertSee('berapa kali angka itu diisi.')->assertSee('angka terakhir dikurangi angka pertama dalam rentang')->assertSee('10 angka terakhir beserta grafik');
});

it('draws the reading chart with labelled axes, limit lines and a tooltip per point', function () {
    foreach ([3 => '24', 2 => '26', 1 => '30'] as $back => $value) {
        addReading($this, $this->today->subDays($back)->toDateString(), 'Voltase', 'number', $value, ['unit' => 'V', 'min_value' => 22, 'max_value' => 28]);
    }
    $rows = app(\App\Reports\ParameterReport::class)->summary([$this->eng->id], array_values($this->range));
    expect($rows[0]['limitMin'])->toBe(22.0)->and($rows[0]['limitMax'])->toBe(28.0);

    loggedIn(User::factory()->role(Role::Admin)->create());
    fakeSchedules([]);
    $html = $this->get(route('laporan.parameters', $this->range))->assertOk()
        ->assertSee('Tanggal pembacaan')->assertSee('Voltase (V)')->assertSee('Batas maks 28')->assertSee('Batas min 22')->assertSee('garis putus-putus = batas tugas')->assertSee('berubah')->getContent();

    // Setiap titik punya tooltip berisi tanggal, pegawai, dan nilai dengan satuan; titik di luar batas diberi tanda.
    expect($html)->toContain('Wayan Teknik · 30 V (di luar batas)')->and(substr_count($html, '<title>'))->toBeGreaterThanOrEqual(3)
        ->and($html)->toContain($this->today->subDays(3)->translatedFormat('d M'));
});

<?php

use App\Attendance\AttendanceApiService;
use App\Attendance\AttendanceUnavailable;
use App\Checklists\PositionSuggester;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

const SCHEDULES_URL = 'attendance.test/api/v1/tasks/schedules*';

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA (Guest Service Agent)']);
    $this->tasks = Task::factory()->for($this->gsa)->count(3)->create();
    $this->ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    Task::factory()->for($this->ra)->create();

    $make = fn (string $name, Department $dept, ?Position $position = null) => Employee::factory()->for($dept)->create(['name' => $name, 'checklist_position_id' => $position?->id, 'position_title' => 'GSA']);
    $this->work = $make('Budi Kerja', $this->fo, $this->gsa);
    $this->off = $make('Sari Libur', $this->fo, $this->gsa);
    $this->leave = $make('Wayan Cuti', $this->fo, $this->gsa);
    $this->none = $make('Komang Tanpa Jadwal', $this->fo, $this->gsa);
    $this->noPosition = $make('Made Tanpa Jabatan', $this->fo);
    $this->hkStaff = $make('Putu Housekeeping', $this->hk, $this->ra);
    $this->hod = User::factory()->hodOf($this->fo)->create();
    $this->today = CarbonImmutable::today();

    $this->schedule = [
        $this->work, $this->off, $this->leave, $this->none, $this->noPosition, $this->hkStaff,
    ];
});

/** Memalsukan jadwal Absensi: [Employee => [status, code]]. Pegawai yang tidak disebut = unscheduled. */
function fakeSchedules(array $statuses): void
{
    $rows = collect($statuses)->map(fn ($s, $i) => ['employee_id' => $s[0]->external_id, 'status' => $s[1], 'code' => $s[2] ?? null])->values()->all();
    fakeAttendance([ATTENDANCE_LOGIN => Http::response(['authenticated' => true, 'employee' => []]), SCHEDULES_URL => Http::response(['data' => $rows])]);
}

function standardSchedule(): void
{
    fakeSchedules([
        [test()->work, 'work', 'P'], [test()->off, 'off'], [test()->leave, 'leave', 'CT'],
        [test()->noPosition, 'work', 'P'], [test()->hkStaff, 'work', 'M'],
    ]);
}

function assignAll(array $data = [])
{
    return test()->post(route('fill.assign-all'), ['date' => test()->today->toDateString(), ...$data]);
}

it('creates checklists only for staff who work today, and reports who was skipped', function () {
    loggedIn($this->hod);
    $existing = makeChecklist($this->work, $this->gsa, $this->today, null); // sudah ada
    $second = Employee::factory()->for($this->fo)->create(['name' => 'Ketut Baru', 'checklist_position_id' => $this->gsa->id]);
    standardSchedule();
    fakeSchedules([[$this->work, 'work', 'P'], [$this->off, 'off'], [$this->leave, 'leave', 'CT'], [$this->noPosition, 'work', 'P'], [$second, 'work', 'S']]);

    assignAll()->assertRedirect(route('fill.assign', ['date' => $this->today->toDateString()]))->assertSessionHas('assign_summary');

    $summary = session('assign_summary');
    expect($summary)->toMatchArray(['created' => 1, 'existing' => 1, 'off' => 1, 'leave' => ['CT' => 1], 'unscheduled' => 1])
        ->and(collect($summary['no_position'])->pluck('name')->all())->toBe(['Made Tanpa Jabatan']);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'schedules') && $request['date'] === $this->today->toDateString());

    $created = Checklist::where('employee_id', $second->id)->sole();
    expect($created)->department_id->toBe($this->fo->id)->position_name->toBe('GSA (Guest Service Agent)')->filled_by->toBe($this->hod->id)->checked_by->toBeNull()
        ->and($created->assigned_at)->not->toBeNull()
        ->and($created->items->pluck('status')->filter()->count())->toBe(0)->and($created->items->count())->toBe(3)
        ->and(Checklist::where('employee_id', $this->off->id)->count())->toBe(0)
        ->and(Checklist::where('employee_id', $this->hkStaff->id)->count())->toBe(0) // departemen lain tidak ikut
        ->and(Checklist::count())->toBe(2);
    $this->get(route('fill.assign'))->assertSee('1 dibuat')->assertSee('Made Tanpa Jabatan');
});

it('is safe to press again and shows what is still to do', function () {
    loggedIn($this->hod);
    standardSchedule();

    assignAll();
    assignAll();

    expect(Checklist::count())->toBe(1)->and(session('assign_summary'))->toMatchArray(['created' => 0, 'existing' => 1]);
    $this->get(route('fill.assign'))->assertSee('Budi Kerja')->assertSee('Belum diisi')->assertSee('3 staff belum punya checklist hari ini')->assertDontSee('Lanjutkan checklist');
});

it('uses the tasks chosen for the staff member and allows past dates but not future ones', function () {
    loggedIn($this->hod);
    $this->work->assignedTasks()->sync([$this->tasks[1]->id]);
    $this->work->update(['custom_tasks' => true]);
    standardSchedule();
    $yesterday = $this->today->subDay()->toDateString();

    assignAll(['date' => $yesterday])->assertRedirect();

    expect(Checklist::sole()->items->pluck('title')->all())->toBe([$this->tasks[1]->title])->and(Checklist::sole()->date->toDateString())->toBe($yesterday);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'schedules') && $request['date'] === $yesterday);
    assignAll(['date' => $this->today->addDays(61)->toDateString()])->assertSessionHasErrors('date');
});

it('creates nothing and says so when the attendance system cannot be reached', function () {
    loggedIn($this->hod);
    fakeAttendance([ATTENDANCE_LOGIN => Http::response(['authenticated' => true, 'employee' => []]), SCHEDULES_URL => Http::response('down', 503)]);

    assignAll()->assertSessionHasErrors(['assign' => 'Sistem absensi tidak dapat dihubungi, jadi jadwal belum bisa dibaca. Coba lagi beberapa saat.']);

    expect(Checklist::count())->toBe(0);
});

it('limits HODs to their own departments, while HR and administrators can do every department', function () {
    loggedIn($this->hod);
    standardSchedule();
    assignAll(['department' => $this->hk->id])->assertForbidden();
    assignAll(['department' => 'all'])->assertRedirect();
    expect(Checklist::pluck('employee_id')->all())->toBe([$this->work->id]);
    $this->post('/logout');

    Checklist::query()->delete();
    loggedIn(userWithRole(Role::Hr));
    standardSchedule();
    assignAll(['department' => 'all'])->assertRedirect();
    expect(Checklist::pluck('employee_id')->sort()->values()->all())->toBe([$this->work->id, $this->hkStaff->id]);
    $this->post('/logout');

    foreach ([Role::Gm, Role::Staff] as $role) {
        loggedIn(userWithRole($role));
        standardSchedule();
        assignAll()->assertForbidden();
        $this->post('/logout');
    }
});

it('records the first manager who fills an assigned checklist as the checker, but not the staff member', function () {
    loggedIn($this->hod);
    standardSchedule();
    assignAll();
    $checklist = Checklist::sole();
    $item = $checklist->items->first();

    $this->patchJson(route('checklists.items.update', [$checklist, $item]), ['status' => 'done'])->assertOk();
    expect($checklist->fresh()->checked_by)->toBe($this->hod->name());
    $this->post('/logout');

    // Staff yang mengisi miliknya sendiri tidak menjadi "pemeriksa".
    Checklist::query()->delete();
    loggedIn($this->hod);
    standardSchedule();
    assignAll();
    $this->post('/logout');
    $staff = User::factory()->for($this->work)->create(['position_id' => null]);
    loggedIn($staff);
    $checklist = Checklist::sole();
    $this->patchJson(route('checklists.items.update', [$checklist, $checklist->items->first()]), ['status' => 'done'])->assertOk();
    expect($checklist->fresh()->checked_by)->toBeNull();
});

it('lets staff open the checklist for the position the HOD set for them', function () {
    $staff = User::factory()->for($this->work)->create(['position_id' => null]);
    loggedIn($staff);
    standardSchedule();

    $this->get(route('fill.create'))->assertOk()->assertDontSee('Buka checklist'); // staff tidak lagi membuka checklist dari halaman ini
    $this->post(route('fill.start'), ['position' => $this->gsa->id, 'employee' => $this->work->id, 'date' => $this->today->toDateString()])->assertRedirect();
    expect(Checklist::sole()->position_id)->toBe($this->gsa->id);
});

it('keeps assigned checklists even when empty, and still prunes abandoned manual ones', function () {
    loggedIn($this->hod);
    standardSchedule();
    assignAll();
    $assigned = Checklist::sole();
    $manual = makeChecklist($this->off, $this->gsa, $this->today->subDays(5), null);
    Checklist::query()->update(['updated_at' => now()->subDays(10)]);

    $this->artisan('checklist:prune-empty')->assertSuccessful();

    expect(Checklist::find($assigned->id))->not->toBeNull()->and(Checklist::find($manual->id))->toBeNull();
});

it('suggests the position from the attendance job title and lets the HOD save it', function () {
    $this->work->update(['checklist_position_id' => null, 'position_title' => 'Guest Service Agent']);
    $this->off->update(['checklist_position_id' => null, 'position_title' => 'GSA']);
    $this->none->update(['checklist_position_id' => null, 'position_title' => 'Kepala Sesuatu']);
    loggedIn($this->hod);

    $page = $this->get(route('team.positions.edit'))->assertOk()->assertSee('Budi Kerja')->assertSee('Saran dari Absensi, belum disimpan')->assertDontSee('Putu Housekeeping');
    // Cocok: "Guest Service Agent" (isi kurung), "GSA" (singkatan), dan Made Tanpa Jabatan ("GSA"); "Kepala Sesuatu" tidak.
    expect(substr_count($page->getContent(), 'Saran dari Absensi'))->toBe(3);

    $this->put(route('team.positions.update'), ['positions' => [$this->work->id => $this->gsa->id, $this->off->id => '', $this->leave->id => $this->gsa->id]])->assertRedirect(route('team.positions.edit'));
    expect($this->work->fresh()->checklist_position_id)->toBe($this->gsa->id)->and($this->off->fresh()->checklist_position_id)->toBeNull();
});

it('does not accept positions from another department, other departments\' staff, or the HOD\'s own position', function () {
    loggedIn($this->hod);

    $this->put(route('team.positions.update'), ['positions' => [$this->work->id => $this->ra->id]])->assertSessionHasErrors("positions.{$this->work->id}");
    $this->put(route('team.positions.update'), ['positions' => [$this->hkStaff->id => $this->ra->id]])->assertForbidden();
    $this->hod->employee->update(['department_id' => $this->fo->id]);
    $this->put(route('team.positions.update'), ['positions' => [$this->hod->employee->id => $this->gsa->id]])->assertForbidden();
    expect($this->work->fresh()->checklist_position_id)->toBe($this->gsa->id)->and($this->hod->employee->fresh()->checklist_position_id)->toBeNull();
    $this->post('/logout');

    foreach ([Role::Gm, Role::Staff] as $role) {
        loggedIn(userWithRole($role));
        $this->get(route('team.positions.edit'))->assertForbidden();
        $this->post('/logout');
    }
});

it('suggests only when exactly one position matches', function () {
    $positions = collect([
        Position::factory()->for($this->fo)->create(['name' => 'Chef de Partie']),
        Position::factory()->for($this->fo)->create(['name' => 'Chef de Partie – Pastry & Bakery']),
        Position::factory()->for($this->fo)->create(['name' => 'Cook – Hot Kitchen']),
        Position::factory()->for($this->fo)->create(['name' => 'Room Attendant (RA)']),
    ]);
    $suggest = fn (?string $title) => app(PositionSuggester::class)->suggest(new Employee(['position_title' => $title]), $positions)?->name;

    expect($suggest('chef de partie'))->toBe('Chef de Partie')           // persis (setelah dirapikan)
        ->and($suggest('Room Attendant'))->toBe('Room Attendant (RA)')    // nama di luar kurung
        ->and($suggest('RA'))->toBe('Room Attendant (RA)')                // singkatan di dalam kurung
        ->and($suggest('Cook'))->toBe('Cook – Hot Kitchen')               // tepat satu yang memuat
        ->and($suggest('Chef'))->toBeNull()                               // ambigu
        ->and($suggest('Manager'))->toBeNull()
        ->and($suggest(null))->toBeNull();
});

it('lets HODs, HR and administrators set the automatic time per department', function () {
    loggedIn($this->hod);
    standardSchedule();

    $this->put(route('fill.auto-assign', $this->fo), ['auto_assign_at' => '05:00'])->assertRedirect();
    expect(substr((string) $this->fo->fresh()->auto_assign_at, 0, 5))->toBe('05:00');
    $this->get(route('admin.settings.edit'))->assertSee('name="auto_assign_at"', false)->assertSee('value="05:00"', false);
    $this->get(route('fill.create'))->assertDontSee('name="auto_assign_at"', false)->assertDontSee('Pengaturan tim');

    $this->put(route('fill.auto-assign', $this->fo), ['auto_assign_at' => '5 pagi'])->assertSessionHasErrors('auto_assign_at');
    $this->put(route('fill.auto-assign', $this->hk), ['auto_assign_at' => '06:00'])->assertForbidden();
    $this->put(route('fill.auto-assign', $this->fo), ['auto_assign_at' => ''])->assertRedirect();
    expect($this->fo->fresh()->auto_assign_at)->toBeNull();
    $this->post('/logout');

    loggedIn(userWithRole(Role::Hr));
    $this->put(route('fill.auto-assign', $this->hk), ['auto_assign_at' => '06:30'])->assertRedirect();
    expect(substr((string) $this->hk->fresh()->auto_assign_at, 0, 5))->toBe('06:30');
    $this->post('/logout');

    loggedIn(userWithRole(Role::Gm));
    $this->put(route('fill.auto-assign', $this->fo), ['auto_assign_at' => '05:00'])->assertForbidden();
});

it('creates the checklists automatically once a day at the chosen time', function () {
    $this->fo->update(['auto_assign_at' => '05:00:00']);
    $this->hk->update(['auto_assign_at' => null]);
    standardSchedule();

    $this->travelTo($this->today->setTime(4, 59));
    $this->artisan('checklist:auto-assign')->assertSuccessful();
    expect(Checklist::count())->toBe(0);

    $this->travelTo($this->today->setTime(5, 0));
    $this->artisan('checklist:auto-assign')->assertSuccessful();
    $created = Checklist::sole();
    expect($created)->employee_id->toBe($this->work->id)->filled_by->toBeNull()->checked_by->toBeNull()
        ->and($created->assigned_at)->not->toBeNull()
        ->and($this->fo->fresh()->auto_assigned_on->toDateString())->toBe($this->today->toDateString());

    // Hari yang sama: tidak diulang. Departemen tanpa jam: tidak disentuh.
    $created->delete();
    $this->travelTo($this->today->setTime(9, 0));
    $this->artisan('checklist:auto-assign')->assertSuccessful();
    expect(Checklist::count())->toBe(0);

    // Besoknya: jalan lagi.
    $this->travelTo($this->today->addDay()->setTime(5, 5));
    fakeSchedules([[$this->work, 'work', 'P']]);
    $this->artisan('checklist:auto-assign')->assertSuccessful();
    expect(Checklist::sole()->date->toDateString())->toBe($this->today->addDay()->toDateString());
});

it('retries later when the schedule cannot be read, without marking the day as done', function () {
    $this->fo->update(['auto_assign_at' => '05:00:00']);
    fakeAttendance([SCHEDULES_URL => Http::response('down', 503)]);
    $this->travelTo($this->today->setTime(5, 10));

    $this->artisan('checklist:auto-assign')->assertSuccessful();
    expect(Checklist::count())->toBe(0)->and($this->fo->fresh()->auto_assigned_on)->toBeNull();

    standardSchedule();
    $this->travelTo($this->today->setTime(5, 15));
    $this->artisan('checklist:auto-assign')->assertSuccessful();
    expect(Checklist::count())->toBe(1)->and($this->fo->fresh()->auto_assigned_on)->not->toBeNull();
});

it('parses the schedule response and ignores rows it does not understand', function () {
    fakeAttendance([SCHEDULES_URL => Http::response(['data' => [
        ['employee_id' => 5, 'status' => 'work', 'code' => 'P'],
        ['employee_id' => 6, 'status' => 'leave', 'code' => 'CT'],
        ['employee_id' => 7, 'status' => 'aneh'],
        ['status' => 'work'],
        'rusak',
    ]])]);

    expect(app(AttendanceApiService::class)->schedules($this->today))->toBe([5 => ['status' => 'work', 'code' => 'P'], 6 => ['status' => 'leave', 'code' => 'CT']]);

    fakeAttendance([SCHEDULES_URL => Http::response(['bukan' => 'daftar'])]);
    expect(fn () => app(AttendanceApiService::class)->schedules($this->today))->toThrow(AttendanceUnavailable::class);
});

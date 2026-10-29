<?php

use App\Checklists\DashboardService;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    foreach (['Check-in tamu', 'Check-out tamu', 'Cek minibar kamar'] as $i => $title) {
        Task::factory()->for($this->gsa)->create(['title' => $title, 'sort' => $i + 1]);
    }
    $this->ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    Task::factory()->for($this->ra)->create(['title' => 'Bersihkan kamar']);

    $this->budi = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso']);
    $this->today = CarbonImmutable::today();
});

/** Checklist Front Office hari ini: dua tugas Done, satu Pending (skor 67%). */
function frontOfficeDay(): void
{
    $checklist = makeChecklist(test()->budi, test()->gsa, test()->today, null);
    $checklist->items()->orderBy('sort')->get()->each(fn ($item, $i) => $item->update($i < 2 ? ['status' => 'done'] : ['status' => 'pending', 'note' => 'Menunggu barang']));
}

it('is the first page of HOD, HR, GM and administrators, and closed to staff', function () {
    loggedIn(User::factory()->role(Role::Staff)->create());
    $this->get(route('dashboard'))->assertForbidden();
    $this->post('/logout');

    foreach ([Role::Hr, Role::Gm, Role::Admin] as $role) {
        loggedIn(userWithRole($role));
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()->assertSee('Status departemen');
        $this->post('/logout');
    }
});

it('shows the HOD only their own department, and GM every department', function () {
    $hod = User::factory()->hodOf($this->fo)->create();
    loggedIn($hod);
    $this->get(route('dashboard'))->assertOk()->assertSee('Front Office')->assertDontSee('Housekeeping');
    $this->post('/logout');

    loggedIn(userWithRole(Role::Gm));
    $this->get(route('dashboard'))->assertOk()->assertSee('Front Office')->assertSee('Housekeeping');
});

it('computes the score, pending tasks and department status from the checklists', function () {
    frontOfficeDay();
    $service = app(DashboardService::class);
    $data = $service->build(userWithRole(Role::Gm), $this->today->subDays(13), $this->today);

    $fo = collect($data['departments'])->firstWhere('name', 'Front Office');
    $hk = collect($data['departments'])->firstWhere('name', 'Housekeeping');
    expect($fo)->score->toBe(67)->checklists->toBe(1)->pending->toBe(1)->state->toBe('full')->positions_checked->toBe(1)
        ->and($hk)->state->toBe('none')->score->toBeNull()->checklists->toBe(0);
    expect($data['kpi'])->score->toBe(67)->checklists->toBe(1)->pending->toBe(1)->unreviewed->toBe(1);
    expect($data['distribution'])->toBe(['done' => 2, 'ongoing' => 0, 'pending' => 1, 'blank' => 0]);
    expect($data['topPending'][0])->title->toBe('Cek minibar kamar')->pending->toBe(1)->department->toBe('Front Office');
    expect($data['days'])->toBe(14)->and($data['trend'])->toHaveCount(14)->and(collect($data['trend'])->last())->score->toBe(67)
        ->and(collect($data['trend'])->first()['score'])->toBeNull();
});

it('renders the charts and insights for the chosen date range', function () {
    frontOfficeDay();
    loggedIn(userWithRole(Role::Gm));

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Tren skor harian')->assertSee('Cek minibar kamar')->assertSee('Belum isi')->assertSee('67%')
        ->assertSee('Status review')->assertSee('Skor per departemen')->assertDontSee('Tampilkan')->assertDontSee('name="periode"', false);

    $this->get(route('dashboard', ['from' => $this->today->toDateString(), 'to' => $this->today->toDateString()]))->assertOk()->assertSee('Cek minibar kamar');
    $this->get(route('dashboard', ['from' => $this->today->subDays(30)->toDateString(), 'to' => $this->today->subDays(20)->toDateString()]))->assertOk()->assertDontSee('Cek minibar kamar');
});

it('swaps a reversed range, caps it, and falls back to the default for invalid dates', function () {
    loggedIn(userWithRole(Role::Gm));
    $this->get(route('dashboard', ['from' => '2026-10-20', 'to' => '2026-10-10']))->assertOk()->assertSee('10 Okt 2026 – 20 Okt 2026');
    $this->get(route('dashboard', ['from' => '2000-01-01', 'to' => $this->today->toDateString()]))->assertOk()->assertSee('92 hari');
    $this->get(route('dashboard', ['from' => 'x', 'to' => '2099-01-01']))->assertOk()->assertSee('1 hari');
});

it('does not show other departments to a HOD, whatever the date range', function () {
    frontOfficeDay();
    loggedIn(User::factory()->hodOf($this->hk)->create());

    $this->get(route('dashboard', ['from' => $this->today->subDays(6)->toDateString(), 'to' => $this->today->toDateString()]))->assertOk()
        ->assertDontSee('Cek minibar kamar')->assertDontSee('Front Office');
});

it('shows a department card progress from the whole range, even when the last day has no checklist', function () {
    makeChecklist($this->budi, $this->gsa, $this->today->subDay(), 'done');
    $data = app(DashboardService::class)->build(userWithRole(Role::Gm), $this->today->subDays(13), $this->today);

    $fo = collect($data['departments'])->firstWhere('name', 'Front Office');
    expect($fo)->score->toBe(100)->checklists->toBe(1)->state->not->toBe('none');
});

it('shows a single day as one date, not as a range', function () {
    loggedIn(userWithRole(Role::Gm));
    $day = $this->today->translatedFormat('d M Y');

    $this->get(route('dashboard', ['from' => $this->today->toDateString(), 'to' => $this->today->toDateString()]))->assertOk()
        ->assertSee($day)->assertDontSee($day.' – '.$day);
});

<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GuestRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $this->room = Room::create(['number' => '1206', 'sort' => 1]);
    $this->runner = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($this->hk)->create(['name' => 'Komang HK'])->id]);
});

function requestAt(array $attributes, string $created, ?int $claimAfter = null, ?int $doneAfter = null): GuestRequest
{
    $time = Carbon::parse($created);
    $item = GuestRequest::create($attributes + ['quantity' => 1, 'priority' => 'biasa', 'status' => 'baru']);
    $item->forceFill([
        'created_at' => $time,
        'claimed_at' => $claimAfter !== null ? $time->copy()->addMinutes($claimAfter) : null,
        'completed_at' => $doneAfter !== null ? $time->copy()->addMinutes($doneAfter) : null,
        'status' => $doneAfter !== null ? 'selesai' : ($claimAfter !== null ? 'diambil' : 'baru'),
    ])->save();

    return $item;
}

it('shows the request workload per hour, kind and staff with handling times', function () {
    $day = now()->toDateString();
    requestAt(['room_id' => $this->room->id, 'kind' => 'towel', 'assigned_department_id' => $this->hk->id, 'claimed_by' => $this->runner->id], "$day 11:05", 10, 25);
    requestAt(['room_id' => $this->room->id, 'kind' => 'towel', 'assigned_department_id' => $this->hk->id, 'claimed_by' => $this->runner->id], "$day 11:40", 30, 50);
    requestAt(['room_id' => $this->room->id, 'kind' => 'mineral_water', 'assigned_department_id' => $this->hk->id], "$day 14:10");

    $report = app(\App\Reports\RequestReport::class)->build(userWithRole(Role::Admin), now()->startOfDay()->toImmutable(), now()->toImmutable(), null);
    expect($report['kpi'])->toMatchArray(['total' => 3, 'done' => 2, 'open' => 1, 'avg_claim' => 20, 'avg_done' => 38, 'on_time' => 50])
        ->and($report['by_hour'][11]['count'])->toBe(2)->and($report['peak_hour'])->toBe(11)
        ->and($report['by_kind'][0])->toMatchArray(['label' => 'Handuk', 'count' => 2, 'claim' => 20])
        ->and($report['by_staff'][0])->toMatchArray(['name' => 'Komang HK', 'taken' => 2, 'done' => 2, 'work' => 18])
        ->and($report['waiting'])->toHaveCount(1);

    loggedIn(userWithRole(Role::Admin));
    $this->get(route('laporan.requests'))->assertOk()->assertSee('Beban per jam')->assertSee('Komang HK')->assertSee('Handuk');
});

it('limits HOD to their departments, hides the tab when the feature is off, and is closed to Staff', function () {
    $day = now()->toDateString();
    requestAt(['room_id' => $this->room->id, 'kind' => 'towel', 'assigned_department_id' => $this->hk->id], "$day 09:00");
    requestAt(['room_id' => $this->room->id, 'kind' => 'laundry', 'assigned_department_id' => $this->eng->id], "$day 09:30");

    $hod = User::factory()->role(Role::Hod)->create(['employee_id' => Employee::factory()->for($this->eng)->create()->id]);
    $hod->departments()->attach($this->eng);
    expect(app(\App\Reports\RequestReport::class)->build($hod, now()->startOfDay()->toImmutable(), now()->toImmutable(), null)['kpi']['total'])->toBe(1);

    loggedIn(userWithRole(Role::Admin));
    $this->get(route('checklists.index'))->assertOk()->assertSee(route('laporan.requests'), false);
    app(ChecklistSettings::class)->put('feature_permintaan', '0');
    $this->get(route('laporan.requests'))->assertNotFound();
    $this->post(route('logout'));
    app('auth')->forgetGuards();

    app(ChecklistSettings::class)->put('feature_permintaan', '1');
    loggedIn($this->runner);
    $this->get(route('laporan.requests'))->assertForbidden();
});

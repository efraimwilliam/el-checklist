<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->room305 = Room::create(['number' => '305', 'floor' => '3', 'sort' => 1]);
    $this->room412 = Room::create(['number' => '412', 'floor' => '4', 'sort' => 2]);
    $this->ac = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->eng->id, 'sort' => 99]);
    $this->linen = IssueCategory::create(['name' => 'Linen uji', 'department_id' => $this->hk->id, 'sort' => 98]);
    $this->admin = User::factory()->role(Role::Admin)->create();
    $this->range = ['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()];
});

function roomIssue($test, Room $room, $category, $department, string $status = 'baru'): RoomIssue
{
    return RoomIssue::create([
        'room_id' => $room->id, 'category_id' => $category->id, 'source' => 'tamu', 'description' => 'Kendala '.$room->number,
        'priority' => 'biasa', 'reported_by' => $test->admin->id, 'assigned_department_id' => $department->id, 'status' => $status,
    ]);
}

it('maps issues per room, flags repeat rooms and breaks them down by category and department', function () {
    foreach (range(1, 3) as $n) {
        roomIssue($this, $this->room305, $this->ac, $this->eng);
    }
    roomIssue($this, $this->room412, $this->linen, $this->hk, 'selesai')->update(['completed_at' => now()->addHours(2)]);

    loggedIn($this->admin);
    fakeSchedules([]);
    $this->get(route('laporan.rooms', $this->range))->assertOk()
        ->assertSee('Peta per lantai')->assertSee('305')->assertSee('412')->assertSee('AC uji')->assertSee('Linen uji')->assertSee('Engineering')->assertSee('Housekeeping')
        ->assertSee('Kamar langganan masalah');

    $report = app(\App\Reports\RoomReport::class)->build($this->admin, array_values($this->range));
    expect($report['total'])->toBe(4)->and($report['open'])->toBe(3)->and($report['repeat'])->toHaveCount(1)->and($report['repeat'][0]['number'])->toBe('305')
        ->and($report['avgHours'])->toBe(2.0);

    $this->get(route('laporan.room', ['room' => $this->room305->id] + $this->range))->assertOk()->assertSee('Kendala 305')->assertDontSee('Kendala 412');
});

it('filters the map by responsible department', function () {
    roomIssue($this, $this->room305, $this->ac, $this->eng);
    roomIssue($this, $this->room412, $this->linen, $this->hk);

    $report = app(\App\Reports\RoomReport::class)->build($this->admin, array_values($this->range), $this->hk->id);
    expect($report['total'])->toBe(1)->and($report['departments'][0]['name'])->toBe('Housekeeping');
});

it('shows a HOD only the issues of their own department and keeps Staff out of the report', function () {
    roomIssue($this, $this->room305, $this->ac, $this->eng);
    roomIssue($this, $this->room412, $this->linen, $this->hk);

    $hod = User::factory()->hodOf($this->eng)->create();
    $report = app(\App\Reports\RoomReport::class)->build($hod, array_values($this->range));
    expect($report['total'])->toBe(1);

    loggedIn(User::factory()->role(Role::Staff)->create());
    $this->get(route('laporan.rooms'))->assertForbidden();
});

it('ignores issues outside the date range', function () {
    $old = roomIssue($this, $this->room305, $this->ac, $this->eng);
    $old->forceFill(['created_at' => CarbonImmutable::today()->subDays(60)])->save();

    $report = app(\App\Reports\RoomReport::class)->build($this->admin, array_values($this->range));
    expect($report['total'])->toBe(0);
});

it('counts recurring issues in the room map report', function () {
    $first = roomIssue($this, $this->room305, $this->ac, $this->eng, 'selesai');
    $second = roomIssue($this, $this->room305, $this->ac, $this->eng);
    $second->update(['recurrence' => 2]);

    loggedIn($this->admin);
    fakeSchedules([]);
    $report = app(\App\Reports\RoomReport::class)->build($this->admin, array_values($this->range));
    expect($report['recurrences'])->toBe(1);
    $this->get(route('laporan.rooms', $this->range))->assertOk()->assertSee('Kendala berulang');
    $this->get(route('laporan.room', ['room' => $this->room305->id] + $this->range))->assertOk()->assertSee('Berulang ke-2');
});

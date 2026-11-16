<?php

use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

// Papan Isi checklist HOD di aplikasi Android (/api/v1/app/papan): sama dengan halaman /isi, aksi lewat controller web.
beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    Task::factory()->for($this->gsa)->count(2)->create();
    $this->budi = Employee::factory()->for($this->fo)->create(['name' => 'Budi', 'checklist_position_id' => $this->gsa->id]);
    $this->sari = Employee::factory()->for($this->fo)->create(['name' => 'Sari', 'checklist_position_id' => $this->gsa->id]);
    $this->hod = User::factory()->hodOf($this->fo)->create();
    $this->today = CarbonImmutable::today();
    fakeAttendance(['attendance.test/api/v1/tasks/schedules*' => Http::response(['data' => [
        ['employee_id' => $this->budi->external_id, 'status' => 'work', 'code' => 'P'],
        ['employee_id' => $this->sari->external_id, 'status' => 'off', 'code' => null],
    ]])]);
});

it('shows the HOD board, starts a checklist, creates the day in one go and reviews', function () {
    Sanctum::actingAs($this->hod);
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.papan', true);

    $board = $this->getJson('/api/v1/app/papan')->assertOk()->assertJsonPath('department_id', $this->fo->id)->assertJsonPath('can_assign_all', true);
    $budi = collect($board->json('staff'))->firstWhere('name', 'Budi');
    expect($budi)->toMatchArray(['state' => 'none', 'can_start' => true, 'position_id' => $this->gsa->id]);

    $id = $this->postJson('/api/v1/app/papan/mulai', ['employee' => $this->budi->id, 'position' => $this->gsa->id, 'date' => $this->today->toDateString()])->assertOk()->json('id');
    // Memulai lagi membuka yang sama, tidak ganda.
    $this->postJson('/api/v1/app/papan/mulai', ['employee' => $this->budi->id, 'position' => $this->gsa->id, 'date' => $this->today->toDateString()])->assertJsonPath('id', $id);
    $this->getJson('/api/v1/app/papan')->assertJsonPath('staff.0.checklists.0.id', $id)->assertJsonPath('staff.0.state', 'empty');

    $this->postJson('/api/v1/app/papan/semua', ['date' => $this->today->toDateString(), 'department' => (string) $this->fo->id])
        ->assertOk()->assertJsonPath('summary.existing', 1)->assertJsonPath('summary.off', 1)->assertJsonPath('summary.created', 0);

    // Belum lengkap = belum bisa direview; lengkap = bisa.
    $this->postJson("/api/v1/app/checklists/{$id}/review")->assertForbidden();
    Checklist::find($id)->items()->update(['status' => 'done']);
    $this->getJson("/api/v1/app/checklists/{$id}")->assertJsonPath('checklist.can_review', true)->assertJsonPath('checklist.employee', 'Budi');
    $this->postJson("/api/v1/app/checklists/{$id}/review")->assertOk();
    expect(Checklist::find($id)->status->value)->toBe('hod_reviewed');
});

it('keeps the board to HOD departments and away from staff', function () {
    Sanctum::actingAs($this->hod);
    $this->postJson('/api/v1/app/papan/semua', ['date' => $this->today->toDateString(), 'department' => (string) $this->hk->id])->assertForbidden();
    $other = Employee::factory()->for($this->hk)->create();
    $this->postJson('/api/v1/app/papan/mulai', ['employee' => $other->id, 'position' => $this->gsa->id, 'date' => $this->today->toDateString()])->assertForbidden();

    Sanctum::actingAs(User::factory()->role(Role::Staff)->create(['employee_id' => $this->budi->id]));
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.papan', false);
    $this->getJson('/api/v1/app/papan')->assertForbidden();
});

it('lists the checklist history for the HOD department and only own checklists for staff', function () {
    $mine = makeChecklist($this->budi, $this->gsa, $this->today, null);
    $other = makeChecklist(Employee::factory()->for($this->fo)->create(['checklist_position_id' => $this->gsa->id]), $this->gsa, $this->today->subDay(), null);

    Sanctum::actingAs($this->hod);
    $this->getJson('/api/v1/app/riwayat?from='.$this->today->subDays(7)->toDateString().'&to='.$this->today->toDateString())
        ->assertOk()->assertJsonCount(2, 'checklists')->assertJsonPath('checklists.0.id', $mine->id)->assertJsonPath('checklists.0.employee', 'Budi');
    $this->getJson('/api/v1/app/riwayat?department='.$this->hk->id)->assertForbidden();

    Sanctum::actingAs(User::factory()->role(Role::Staff)->create(['employee_id' => $this->budi->id]));
    $this->getJson('/api/v1/app/riwayat?from='.$this->today->subDays(7)->toDateString())->assertOk()->assertJsonCount(1, 'checklists')->assertJsonPath('checklists.0.id', $mine->id);
    expect($other->id)->not->toBe($mine->id);
});

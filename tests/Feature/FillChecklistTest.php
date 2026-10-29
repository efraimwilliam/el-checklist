<?php

use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

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

function fillQuery(array $override = []): array
{
    return array_filter([
        'position' => test()->gsa->id, 'employee' => test()->budi->id, 'date' => test()->today->toDateString(), 'mulai' => 1, ...$override,
    ], fn ($value) => $value !== null);
}

/** Isian lengkap untuk checklist GSA Budi (semua Done, kecuali diubah). */
function fillPayload(array $override = []): array
{
    return [
        'position' => test()->gsa->id, 'employee' => test()->budi->id, 'date' => test()->today->toDateString(),
        'checked_by' => 'Bu Ayu', 'items' => answers(test()->tasks->pluck('id')), ...$override,
    ];
}

it('shows the HOD only their own department in the selector', function () {
    loggedIn($this->hod);

    $this->get(route('fill.create'))->assertOk()
        ->assertSee('GSA (3 tugas)')->assertSee('Duty Manager (1 tugas)')
        ->assertDontSee('Tanpa Tugas')->assertDontSee('Room Attendant')
        ->assertSee('Budi Santoso')->assertDontSee('Sari Dewi')
        ->assertSee('value="'.e($this->hod->name()).'"', false); // "Dicek oleh" bawaan = nama HOD
});

it('hides inactive staff and shows the tasks only after starting', function () {
    Employee::factory()->for($this->fo)->create(['name' => 'Sudah Keluar', 'is_active' => false]);
    loggedIn($this->hod);

    $this->get(route('fill.create'))->assertDontSee('Sudah Keluar')->assertDontSee('Check-in tamu');
    $this->get(route('fill.create', fillQuery()))->assertOk()
        ->assertSee('Check-in tamu')->assertSee('Handover shift')->assertSee('Sisa tugas = Done')->assertSee('Simpan checklist')->assertSee('Tugas tambahan');
});

it('lets the HOD save a complete checklist for a staff member', function () {
    loggedIn($this->hod);
    $payload = fillPayload(['note' => 'Lobby ramai', 'items' => answers($this->tasks->pluck('id'), 'done', [$this->tasks[1]->id => ['status' => 'ongoing', 'note' => 'Menunggu tamu']])]);

    $this->post(route('fill.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();

    $checklist = Checklist::sole();
    expect($checklist)->employee_id->toBe($this->budi->id)->department_id->toBe($this->fo->id)->position_name->toBe('GSA')
        ->checked_by->toBe('Bu Ayu')->filled_by->toBe($this->hod->id)->note->toBe('Lobby ramai')
        ->and($checklist->status->value)->toBe('saved')
        ->and($checklist->date->toDateString())->toBe($this->today->toDateString())
        ->and($checklist->items->pluck('status.value')->all())->toBe(['done', 'ongoing', 'done'])
        ->and($checklist->items[1]->note)->toBe('Menunggu tamu')
        ->and($checklist->score())->toBe(67);
    $this->get(route('checklists.show', $checklist))->assertOk()->assertSee('Budi Santoso')->assertSee('Bu Ayu')->assertSee('67%');
});

it('only saves complete checklists: every task needs a status and Pending needs a reason', function () {
    loggedIn($this->hod);
    [$a, $b, $c] = $this->tasks->pluck('id')->all();

    $this->post(route('fill.store'), fillPayload(['items' => [$a => ['status' => 'done']]]))->assertSessionHasErrors(["items.{$b}.status", "items.{$c}.status"]);
    $this->post(route('fill.store'), fillPayload(['items' => answers([$a, $b, $c], 'done', [$b => ['status' => 'pending', 'note' => ' ']])]))->assertSessionHasErrors("items.{$b}.note");
    $this->post(route('fill.store'), fillPayload(['items' => answers([$a, $b, $c], 'done', [$c => ['status' => 'selesai']])]))->assertSessionHasErrors("items.{$c}.status");

    expect(Checklist::count())->toBe(0);
});

it('saves extra tasks, ignoring blank ones, and does not count them in the score', function () {
    loggedIn($this->hod);

    $this->post(route('fill.store'), fillPayload(['extras' => [
        ['title' => 'Membantu banquet', 'status' => 'done', 'note' => 'Sampai malam'],
        ['title' => '  ', 'status' => 'pending', 'note' => ''],
        ['title' => 'Antar tamu VIP', 'status' => 'ongoing', 'note' => ''],
    ]]))->assertRedirect();

    $checklist = Checklist::sole();
    expect($checklist->extraTasks->pluck('title')->all())->toBe(['Membantu banquet', 'Antar tamu VIP'])
        ->and($checklist->extraTasks[0]->note)->toBe('Sampai malam')
        ->and($checklist->score())->toBe(100);
    $this->get(route('checklists.show', $checklist))->assertSee('Membantu banquet')->assertSee('Sampai malam');
});

it('updates instead of duplicating when the same staff, position and date are saved again', function () {
    loggedIn($this->hod);
    $existing = makeChecklist($this->budi, $this->gsa, $this->today);

    $this->get(route('fill.create', fillQuery()))->assertOk()->assertSee('sudah pernah disimpan');

    $items = $existing->items->pluck('id');
    $this->put(route('checklists.update', $existing), [
        'checked_by' => 'Pak Wayan', 'note' => 'Diperbaiki', 'items' => answers($items, 'ongoing', [$items[0] => ['status' => 'done', 'note' => '']]),
        'extras' => [['title' => 'Bantu parkir', 'status' => 'done']],
    ])->assertRedirect(route('checklists.show', $existing));

    $existing->refresh();
    expect(Checklist::count())->toBe(1)
        ->and($existing->items->pluck('status.value')->all())->toBe(['done', 'ongoing', 'ongoing'])
        ->and($existing->checked_by)->toBe('Pak Wayan')->and($existing->note)->toBe('Diperbaiki')->and($existing->filled_by)->toBe($this->hod->id)
        ->and($existing->extraTasks->pluck('title')->all())->toBe(['Bantu parkir']);

    // Simpan ulang sebagai "baru" (mis. dari perangkat lain): tetap satu checklist.
    $this->post(route('fill.store'), fillPayload())->assertRedirect(route('fill.create', $existing->fillParams()))->assertSessionHasErrors('duplicate');
    expect(Checklist::count())->toBe(1);
});

it('allows past dates but never future ones', function () {
    loggedIn($this->hod);
    $yesterday = $this->today->subDay()->toDateString();

    $this->get(route('fill.create', fillQuery(['date' => $this->today->addDay()->toDateString()])))->assertSee('Tanggal tidak boleh di masa depan')->assertDontSee('Check-in tamu');
    $this->post(route('fill.store'), fillPayload(['date' => $this->today->addDay()->toDateString()]))->assertSessionHasErrors('date');
    expect(Checklist::count())->toBe(0);

    $this->post(route('fill.store'), fillPayload(['date' => $yesterday]))->assertRedirect();
    expect(Checklist::sole()->date->toDateString())->toBe($yesterday);
});

it('remembers who checked from the last saved checklist', function () {
    loggedIn($this->hod);
    $this->post(route('fill.store'), fillPayload(['checked_by' => 'Ni Made Ayu (Exec. HK)']));

    $this->get(route('fill.create'))->assertSee('value="Ni Made Ayu (Exec. HK)"', false);
});

it('keeps one checklist per position for the same staff and date', function () {
    loggedIn($this->hod);
    makeChecklist($this->budi, $this->gsa, $this->today);

    $this->post(route('fill.store'), fillPayload(['position' => $this->dm->id, 'items' => answers([$this->dmTask->id])]))->assertRedirect();

    expect(Checklist::count())->toBe(2);
});

it('does not let a HOD fill for another department or mix departments', function () {
    loggedIn($this->hod);

    $this->post(route('fill.store'), fillPayload(['employee' => $this->sari->id, 'position' => $this->ra->id, 'items' => answers([$this->raTask->id])]))->assertForbidden();
    $this->post(route('fill.store'), fillPayload(['position' => $this->ra->id, 'items' => answers([$this->raTask->id])]))->assertForbidden(); // jabatan HK untuk staff FO
    $this->get(route('fill.create', fillQuery(['employee' => $this->sari->id, 'position' => $this->ra->id])))->assertDontSee('Bersihkan kamar');

    $this->budi->update(['is_active' => false]);
    $this->post(route('fill.store'), fillPayload())->assertForbidden(); // staff nonaktif
    expect(Checklist::count())->toBe(0);
});

it('lets staff with an account fill only their own checklist', function () {
    $staff = User::factory()->for($this->budi)->create(['position_id' => $this->gsa->id]);
    loggedIn($staff);

    $this->get(route('checklists.today'))->assertRedirect(route('fill.create'));
    $this->get(route('fill.create'))->assertOk()->assertSee('Checklist saya')->assertSee('Check-in tamu')->assertDontSee('Dicek oleh');

    $this->post(route('fill.store'), fillPayload(['checked_by' => null]))->assertRedirect();
    expect(Checklist::sole())->checked_by->toBeNull()->filled_by->toBe($staff->id);

    $other = Employee::factory()->for($this->fo)->create();
    $this->post(route('fill.store'), fillPayload(['employee' => $other->id]))->assertForbidden();
    $this->post(route('fill.store'), fillPayload(['position' => $this->dm->id, 'items' => answers([$this->dmTask->id])]))->assertForbidden();
    expect(Checklist::count())->toBe(1);
});

it('tells staff without a checklist position to contact HR', function () {
    loggedIn(User::factory()->for($this->budi)->create(['position_id' => null]));

    $this->get(route('fill.create'))->assertOk()->assertSee('Jabatan checklist Anda belum diatur');
});

it('does not let the GM fill checklists', function () {
    loggedIn(userWithRole(Role::Gm));

    $this->get(route('fill.create'))->assertForbidden();
    $this->post(route('fill.store'), fillPayload())->assertForbidden();
});

it('lets HR and administrators fill for any department', function (Role $role) {
    loggedIn(userWithRole($role));

    $this->get(route('fill.create'))->assertOk()->assertSee('Front Office')->assertSee('Housekeeping');
    $this->get(route('fill.create', ['department' => $this->hk->id]))->assertSee('Room Attendant (1 tugas)')->assertSee('Sari Dewi')->assertDontSee('Budi Santoso');
    $this->post(route('fill.store'), fillPayload(['employee' => $this->sari->id, 'position' => $this->ra->id, 'items' => answers([$this->raTask->id])]))->assertRedirect();
    expect(Checklist::sole()->department_id)->toBe($this->hk->id);
})->with([Role::Hr, Role::Admin]);

it('uses the tasks chosen for the staff member, but only for new checklists', function () {
    $old = makeChecklist($this->budi, $this->gsa, $this->today->subDay());
    $this->budi->assignedTasks()->sync([$this->tasks[2]->id, $this->dmTask->id]);
    $this->budi->update(['custom_tasks' => true]);
    loggedIn($this->hod);

    // Tugas jabatan yang dipilih (GSA) lebih dulu, lalu tugas jabatan lain.
    $this->get(route('fill.create', fillQuery()))->assertSeeInOrder(['Handover shift', 'Review laporan harian'])->assertDontSee('Check-in tamu');
    $this->post(route('fill.store'), fillPayload(['items' => answers([$this->tasks[2]->id, $this->dmTask->id])]))->assertRedirect();

    expect(Checklist::latest('id')->first()->items->pluck('title')->all())->toBe(['Handover shift', 'Review laporan harian'])
        ->and(Checklist::find($old->id)->items->count())->toBe(3);
});

it('says so when the position has no active tasks left', function () {
    $this->tasks->each->update(['is_active' => false]);
    loggedIn(userWithRole(Role::Admin));

    // Jabatan tanpa tugas aktif tidak muncul di pilihan; membukanya langsung menampilkan pesan kosong.
    $this->get(route('fill.create'))->assertDontSee('GSA (');
    $this->get(route('fill.create', fillQuery()))->assertOk()->assertDontSee('Simpan checklist');
});

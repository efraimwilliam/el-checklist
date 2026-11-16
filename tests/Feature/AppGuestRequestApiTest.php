<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GuestRequest;
use App\Models\Room;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// Permintaan tamu di aplikasi Android (/api/v1/app/permintaan): aksi lewat controller web yang sama, jawaban JSON.
function appRequestStaff(Department $department): User
{
    return User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
}

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    Room::create(['number' => '1206', 'floor' => '1', 'sort' => 1]);
    $this->foStaff = appRequestStaff($this->fo);
    $this->hkStaff = appRequestStaff($this->hk);
});

it('logs several requests from the app and lets Housekeeping claim, release and finish them', function () {
    Sanctum::actingAs($this->foStaff);
    $this->getJson('/api/v1/app/permintaan/form')->assertOk()->assertJsonPath('kinds.0.key', 'mineral_water')->assertJsonPath('kinds.0.has_quantity', true);
    $this->postJson('/api/v1/app/permintaan', ['room' => '1206', 'kinds' => ['mineral_water', 'towel'], 'qty' => ['mineral_water' => 2], 'priority' => 'biasa'])
        ->assertOk()->assertJsonPath('message', '2 permintaan untuk kamar 1206 dicatat.');
    $this->postJson('/api/v1/app/permintaan', ['room' => '9999', 'kinds' => ['towel'], 'priority' => 'biasa'])->assertStatus(422)->assertJsonValidationErrors('room');

    $water = GuestRequest::where('kind', 'mineral_water')->firstOrFail();
    Sanctum::actingAs($this->hkStaff);
    $this->getJson('/api/v1/app/permintaan')->assertOk()->assertJsonCount(2, 'requests')->assertJsonPath('new_for_me', 2)
        ->assertJsonPath('requests.0.label', '2× Air mineral')->assertJsonPath('requests.0.room', '1206')->assertJsonPath('requests.0.can.claim', true);

    $this->postJson("/api/v1/app/permintaan/{$water->id}/ambil")->assertOk();
    $this->postJson("/api/v1/app/permintaan/{$water->id}/lepas")->assertOk();
    $this->postJson("/api/v1/app/permintaan/{$water->id}/ambil")->assertOk();
    $this->postJson("/api/v1/app/permintaan/{$water->id}/selesai")->assertOk();
    expect($water->refresh()->status)->toBe('selesai')->and($water->completed_by)->toBe($this->hkStaff->id);
    $this->getJson('/api/v1/app/permintaan?status=selesai')->assertJsonPath('requests.0.id', $water->id);

    // Yang mencatat boleh menghapus selama masih Baru; staff departemen lain tidak boleh mengambil.
    $towel = GuestRequest::where('kind', 'towel')->firstOrFail();
    Sanctum::actingAs(appRequestStaff($this->eng));
    $this->postJson("/api/v1/app/permintaan/{$towel->id}/ambil")->assertForbidden();
    Sanctum::actingAs($this->foStaff);
    $this->deleteJson("/api/v1/app/permintaan/{$towel->id}")->assertOk();
    expect(GuestRequest::count())->toBe(1);
});

it('refuses claiming a request someone else took, and hides the menu when the feature is off', function () {
    $item = GuestRequest::create(['room_id' => Room::value('id'), 'kind' => 'towel', 'quantity' => 1, 'priority' => 'biasa',
        'requested_by' => $this->foStaff->id, 'assigned_department_id' => $this->hk->id, 'status' => 'diambil', 'claimed_by' => appRequestStaff($this->hk)->id, 'claimed_at' => now()]);

    Sanctum::actingAs($this->hkStaff);
    $this->postJson("/api/v1/app/permintaan/{$item->id}/ambil")->assertForbidden(); // policy: hanya yang Baru bisa diambil
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.permintaan', true);

    app(ChecklistSettings::class)->put(ChecklistSettings::featureSettingKey('permintaan'), '0');
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.permintaan', false);
    $this->getJson('/api/v1/app/permintaan')->assertForbidden();
});

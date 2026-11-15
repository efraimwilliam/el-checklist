<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GuestRequest;
use App\Models\Room;
use App\Models\User;

function requestStaff(Department $department, Role $role = Role::Staff): User
{
    return User::factory()->role($role)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
}

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $this->room = Room::create(['number' => '1206', 'floor' => '1', 'sort' => 1]);
    $this->foStaff = requestStaff($this->fo);
    $this->hkStaff = requestStaff($this->hk);
    $this->engStaff = requestStaff($this->eng);
});

it('lets Front Office log several requests for one room at once, going to Housekeeping by default', function () {
    loggedIn($this->foStaff);

    $this->post(route('permintaan.store'), ['room' => '1206', 'kinds' => ['mineral_water', 'towel', 'mur'], 'qty' => ['mineral_water' => 2, 'towel' => 1], 'priority' => 'mendesak', 'note' => 'Tamu menunggu'])
        ->assertRedirect(route('permintaan.index'));

    expect(GuestRequest::count())->toBe(3);
    $water = GuestRequest::where('kind', 'mineral_water')->firstOrFail();
    expect($water->quantity)->toBe(2)->and($water->assigned_department_id)->toBe($this->hk->id)->and($water->requested_by)->toBe($this->foStaff->id)
        ->and($water->status)->toBe('baru')->and($water->priority)->toBe('mendesak')->and($water->label())->toBe('2× Air mineral')
        ->and(GuestRequest::where('kind', 'mur')->value('quantity'))->toBe(1);
});

it('rejects unknown rooms, empty selections and unknown kinds', function () {
    loggedIn($this->foStaff);

    $this->post(route('permintaan.store'), ['room' => '9999', 'kinds' => ['towel'], 'priority' => 'biasa'])->assertSessionHasErrors(['room' => 'Kamar 9999 tidak ada di data kamar. Periksa lagi nomornya.']);
    $this->post(route('permintaan.store'), ['room' => '1206', 'priority' => 'biasa'])->assertSessionHasErrors('kinds');
    $this->post(route('permintaan.store'), ['room' => '1206', 'kinds' => ['bom'], 'priority' => 'biasa'])->assertSessionHasErrors('kinds.0');
    $this->room->update(['is_active' => false]);
    $this->post(route('permintaan.store'), ['room' => '1206', 'kinds' => ['towel'], 'priority' => 'biasa'])->assertSessionHasErrors('room');
    expect(GuestRequest::count())->toBe(0);
});

it('shows requests only to the reporter, the target department, and see-all roles', function () {
    $item = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'quantity' => 1, 'note' => 'catatan-uji-unik', 'priority' => 'biasa', 'requested_by' => $this->foStaff->id, 'assigned_department_id' => $this->hk->id]);

    loggedIn($this->hkStaff);
    $this->get(route('permintaan.index'))->assertOk()->assertSee('catatan-uji-unik');
    $this->post('/logout');

    loggedIn($this->engStaff);
    $this->get(route('permintaan.index'))->assertOk()->assertDontSee('catatan-uji-unik');
    $this->post(route('permintaan.claim', $item))->assertForbidden();
    $this->post('/logout');

    loggedIn(userWithRole(Role::Gm));
    $this->get(route('permintaan.index'))->assertOk()->assertSee('catatan-uji-unik');
    $this->post(route('permintaan.claim', $item))->assertForbidden();
});

it('lets Housekeeping take and finish a request, recording the times', function () {
    $item = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'laundry', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foStaff->id, 'assigned_department_id' => $this->hk->id]);

    loggedIn($this->hkStaff);
    $this->post(route('permintaan.claim', $item))->assertRedirect();
    $item->refresh();
    expect($item->status)->toBe('diambil')->and($item->claimed_by)->toBe($this->hkStaff->id)->and($item->claimed_at)->not->toBeNull();

    $this->post(route('permintaan.complete', $item))->assertRedirect();
    $item->refresh();
    expect($item->status)->toBe('selesai')->and($item->completed_by)->toBe($this->hkStaff->id)->and($item->completed_at)->not->toBeNull();

    // Yang sudah selesai tidak bisa diambil / diselesaikan lagi.
    $this->post(route('permintaan.claim', $item))->assertForbidden();
    $this->post(route('permintaan.complete', $item))->assertForbidden();
});

it('lets a request be finished straight from new and releases a claim', function () {
    $direct = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foStaff->id, 'assigned_department_id' => $this->hk->id]);
    $other = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'pillow', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foStaff->id, 'assigned_department_id' => $this->hk->id]);

    loggedIn($this->hkStaff);
    $this->post(route('permintaan.complete', $direct))->assertRedirect();
    expect($direct->refresh()->status)->toBe('selesai')->and($direct->claimed_by)->toBe($this->hkStaff->id);

    $this->post(route('permintaan.claim', $other));
    $this->post(route('permintaan.release', $other))->assertRedirect();
    expect($other->refresh()->status)->toBe('baru')->and($other->claimed_by)->toBeNull();
});

it('only lets the reporter delete a new request, and Administrator any', function () {
    $item = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foStaff->id, 'assigned_department_id' => $this->hk->id]);

    loggedIn($this->hkStaff);
    $this->delete(route('permintaan.destroy', $item))->assertForbidden();
    $this->post('/logout');

    loggedIn($this->foStaff);
    $this->delete(route('permintaan.destroy', $item))->assertRedirect();
    expect(GuestRequest::count())->toBe(0);
});

it('counts new requests in the menu badge for the target department only', function () {
    GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foStaff->id, 'assigned_department_id' => $this->hk->id]);

    expect($this->hkStaff->newGuestRequestCount())->toBe(1)->and($this->engStaff->newGuestRequestCount())->toBe(0);
});

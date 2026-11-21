<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\ChronologyReport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GuestRequest;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;

function featureUser(Role $role = Role::Staff): User
{
    return User::factory()->role($role)->create(['employee_id' => Employee::factory()->for(Department::factory()->create())->create()->id]);
}

function turnOff(string ...$features): void
{
    foreach ($features as $feature) {
        app(ChecklistSettings::class)->put(ChecklistSettings::featureSettingKey($feature), '0');
    }
}

it('has every feature on by default except chat', function () {
    $settings = app(ChecklistSettings::class);

    foreach (array_keys(ChecklistSettings::FEATURES) as $key) {
        expect($settings->featureEnabled($key))->toBe($key !== 'obrolan');
    }
});

it('lets only the Administrator switch features, unchecked boxes meaning off', function () {
    loggedIn(userWithRole(Role::Admin));
    $this->get(route('admin.settings.edit'))->assertOk()->assertSee('Permintaan tamu')->assertSee('Kendala dan Peta kamar')->assertSee('Chronology Report');

    $this->put(route('admin.settings.features.update'), ['features' => ['permintaan' => '1', 'obrolan' => '1', 'laporan' => '1']])->assertRedirect();
    $settings = app(ChecklistSettings::class);
    expect($settings->featureEnabled('permintaan'))->toBeTrue()->and($settings->featureEnabled('obrolan'))->toBeTrue()
        ->and($settings->featureEnabled('kendala'))->toBeFalse()->and($settings->featureEnabled('chronology'))->toBeFalse();
    $this->post('/logout');

    loggedIn(userWithRole(Role::Hr));
    $this->put(route('admin.settings.features.update'), ['features' => []])->assertForbidden();
});

it('closes guest request pages and hides the menu when Permintaan is off', function () {
    $user = featureUser();
    $room = Room::create(['number' => '1206', 'sort' => 1]);
    $item = GuestRequest::create(['room_id' => $room->id, 'kind' => 'towel', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $user->id]);
    loggedIn($user);
    $this->get(route('checklists.index'))->assertOk()->assertSee(route('permintaan.index'), false);

    turnOff('permintaan');
    $this->get(route('permintaan.index'))->assertNotFound();
    $this->post(route('permintaan.store'), ['room' => '1206', 'kinds' => ['towel'], 'priority' => 'biasa'])->assertNotFound();
    $this->delete(route('permintaan.destroy', $item))->assertNotFound();
    $this->get(route('checklists.index'))->assertOk()->assertDontSee(route('permintaan.index'), false);
    expect(GuestRequest::count())->toBe(1);
});

it('closes issues, the room map, and issue chat when Kendala is off, keeping the data', function () {
    $admin = userWithRole(Role::Admin);
    $room = Room::create(['number' => '305', 'sort' => 1]);
    $issue = RoomIssue::create(['room_id' => $room->id, 'source' => 'tamu', 'description' => 'AC bocor', 'priority' => 'biasa', 'reported_by' => $admin->id, 'status' => 'baru']);
    loggedIn($admin);

    turnOff('kendala');
    $this->get(route('kamar.issues.index'))->assertNotFound();
    $this->get(route('kamar.issues.show', $issue))->assertNotFound();
    $this->get(route('laporan.rooms'))->assertNotFound();
    $this->get(route('dashboard'))->assertOk()->assertSee(route('laporan.index'), false)->assertDontSee(route('laporan.rooms'), false)->assertDontSee(route('kamar.issues.index'), false);
    // Data kamar tetap bisa diatur.
    $this->get(route('admin.rooms.index'))->assertOk();
    expect(RoomIssue::count())->toBe(1);
});

it('closes Chronology Report and the Laporan menu when they are off', function () {
    $admin = userWithRole(Role::Admin);
    loggedIn($admin);

    turnOff('chronology', 'laporan');
    $this->get(route('chronology.index'))->assertNotFound();
    $this->get(route('chronology.create'))->assertNotFound();
    $this->get(route('laporan.index'))->assertNotFound();
    $this->get(route('laporan.issues'))->assertNotFound();
    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('chronology.index'), false)->assertDontSee(route('laporan.index'), false);
});

it('groups Kendala, Permintaan tamu, and Chronology Report under one Operasional menu, or a single link when only one item is left', function () {
    loggedIn(featureUser());

    $this->get(route('checklists.index'))->assertOk()->assertSee('Operasional')
        ->assertSee(route('kamar.issues.index'), false)->assertSee(route('permintaan.index'), false)->assertSee(route('chronology.index'), false);

    turnOff('kendala', 'chronology');
    $this->get(route('checklists.index'))->assertOk()->assertSee('Operasional')->assertSee(route('permintaan.index'), false)->assertSee(route('lost.index'), false)
        ->assertDontSee(route('kamar.issues.index'), false)->assertDontSee(route('chronology.index'), false);
});

it('opens the request and issue dialogs from shortcut links with the room filled in', function () {
    Room::create(['number' => '1206', 'sort' => 1]);
    loggedIn(featureUser());
    $autoOpen = fn (string $id) => '/id="'.$id.'"\s+data-autoopen/';

    $html = $this->get(route('permintaan.index', ['catat' => 1, 'kamar' => '1206', 'jenis' => 'towel']))->assertOk()
        ->assertSee('value="1206"', false)->assertDontSee('req-rooms', false)->assertSee('value="towel" class="h-5 w-5" checked', false)->getContent();
    expect($html)->toMatch($autoOpen('request-dialog'));
    expect($this->get(route('permintaan.index'))->assertOk()->getContent())->not->toMatch($autoOpen('request-dialog'));

    $html = $this->get(route('kamar.issues.index', ['catat' => 1, 'kamar' => '1206']))->assertOk()->assertSee('name="room_number" value="1206"', false)->assertDontSee('issue-room-list', false)->getContent();
    expect($html)->toMatch($autoOpen('issue-dialog'));
    $this->get(route('chronology.create', ['kamar' => '1206']))->assertOk()->assertSee('name="guests[0][room_number]" value="1206"', false);
});

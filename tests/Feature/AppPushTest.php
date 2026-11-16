<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;
use App\Push\AppPushSender;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

// Notifikasi aplikasi Android (Firebase): kendala / permintaan baru ke HP yang dituju, sesudah jawaban terkirim.
function appPushUser(Department $department, ?string $fcm = null): User
{
    $user = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
    if ($fcm) {
        $user->createToken('HP')->accessToken->forceFill(['fcm_token' => $fcm])->save();
    }

    return $user;
}

beforeEach(function () {
    $this->fake = new FakeAppPushSender;
    app()->instance(AppPushSender::class, $this->fake);
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->room = Room::create(['number' => '305', 'floor' => '3', 'sort' => 1]);
    $this->ac = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->eng->id, 'sort' => 99]);
    $this->foStaff = appPushUser($this->fo, 'hp-fo');
    $this->engStaff = appPushUser($this->eng, 'hp-eng');
    $this->hkStaff = appPushUser($this->hk, 'hp-hk');
});

it('stores the device push token on the login token, one account per device', function () {
    // Token sungguhan (bukan Sanctum::actingAs) supaya kolomnya tersimpan.
    $plain = $this->hkStaff->createToken('Pixel')->plainTextToken;

    $this->withToken($plain)->postJson('/api/v1/app/push-token', ['token' => 'hp-eng'])->assertOk();

    expect(PersonalAccessToken::findToken($plain)->fcm_token)->toBe('hp-eng')
        ->and(PersonalAccessToken::where('fcm_token', 'hp-eng')->count())->toBe(1); // diambil alih dari akun Engineering
});

it('notifies only the department a new issue goes to, not the reporter', function () {
    Sanctum::actingAs($this->foStaff);
    $this->postJson('/api/v1/app/kendala', ['place_type' => 'room', 'room_id' => $this->room->id, 'category_id' => $this->ac->id,
        'source' => 'tamu', 'priority' => 'mendesak', 'description' => 'AC bocor, tamu marah'])->assertOk();

    expect($this->fake->sent)->toHaveCount(1)
        ->and($this->fake->sent[0]['token'])->toBe('hp-eng')
        ->and($this->fake->sent[0]['message'])->toMatchArray(['title' => 'Kendala baru · Kamar 305', 'body' => 'AC uji · Mendesak'])
        ->and($this->fake->sent[0]['message']['data'])->toBe(['type' => 'kendala', 'id' => (string) RoomIssue::value('id')])
        ->and(json_encode($this->fake->sent))->not->toContain('marah'); // uraian tidak tampil di layar kunci
});

it('sends one notification per room for several requests and forgets dead tokens', function () {
    $other = appPushUser($this->hk, 'hp-hk-lama');
    $this->fake->results['hp-hk-lama'] = AppPushSender::GONE;

    Sanctum::actingAs($this->foStaff);
    $this->postJson('/api/v1/app/permintaan', ['room' => '305', 'kinds' => ['mineral_water', 'towel'], 'qty' => ['mineral_water' => 2], 'priority' => 'biasa'])->assertOk();

    expect(collect($this->fake->sent)->pluck('token')->sort()->values()->all())->toBe(['hp-hk', 'hp-hk-lama'])
        ->and($this->fake->sent[0]['message']['body'])->toBe('2× Air mineral, 1× Handuk')
        ->and($this->fake->sent[0]['message']['title'])->toBe('Permintaan tamu · Kamar 305')
        ->and(PersonalAccessToken::where('tokenable_id', $other->id)->value('fcm_token'))->toBeNull();
});

it('sends nothing when the feature is off or the login token has expired', function () {
    app(ChecklistSettings::class)->put(ChecklistSettings::featureSettingKey('permintaan'), '0');
    PersonalAccessToken::where('fcm_token', 'hp-eng')->update(['created_at' => now()->subDays(2)]);

    loggedIn($this->foStaff);
    RoomIssue::create(['room_id' => $this->room->id, 'category_id' => $this->ac->id, 'source' => 'tamu', 'description' => 'x', 'priority' => 'biasa',
        'reported_by' => $this->foStaff->id, 'assigned_department_id' => $this->eng->id, 'status' => 'baru']);
    $this->get('/up');

    expect($this->fake->sent)->toBe([]);
});

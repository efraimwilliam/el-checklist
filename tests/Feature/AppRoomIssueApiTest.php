<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// Kendala di aplikasi Android (/api/v1/app/kendala): aksi lewat controller web yang sama, jawaban JSON.
function appUserOf(Department $department): User
{
    return User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
}

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->room = Room::create(['number' => '305', 'floor' => '3', 'sort' => 1]);
    $this->ac = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->eng->id, 'sort' => 99]);
    $this->foStaff = appUserOf($this->fo);
    $this->engStaff = appUserOf($this->eng);
});

function appReport(array $extra = [])
{
    return test()->postJson('/api/v1/app/kendala', $extra + [
        'place_type' => 'room', 'room_id' => test()->room->id, 'category_id' => test()->ac->id,
        'source' => 'tamu', 'priority' => 'biasa', 'description' => 'AC tidak dingin',
    ]);
}

it('logs an issue from the app and asks before creating a duplicate', function () {
    Sanctum::actingAs($this->foStaff);

    $this->getJson('/api/v1/app/kendala/form')->assertOk()->assertJsonPath('rooms.0.number', '305')->assertJsonPath('sources.0.key', 'tamu');
    $id = appReport()->assertOk()->assertJsonPath('message', 'Kendala dicatat.')->json('id');
    expect(RoomIssue::find($id)->assigned_department_id)->toBe($this->eng->id);

    appReport()->assertStatus(409)->assertJsonPath('duplicate.id', $id);
    appReport(['merge_into' => $id, 'description' => 'Tamu menelepon lagi'])->assertOk()->assertJsonPath('id', $id);
    appReport(['force_new' => true])->assertOk();
    appReport(['description' => ''])->assertStatus(422)->assertJsonValidationErrors('description');

    expect(RoomIssue::count())->toBe(2)->and(RoomIssue::find($id)->updates()->where('kind', 'laporan')->count())->toBe(1);
});

it('lets the assigned department claim, work, and finish an issue with photos', function () {
    Storage::fake('local');
    $issue = RoomIssue::create(['room_id' => $this->room->id, 'category_id' => $this->ac->id, 'source' => 'tamu', 'description' => 'AC bocor',
        'priority' => 'mendesak', 'reported_by' => $this->foStaff->id, 'assigned_department_id' => $this->eng->id, 'status' => 'baru']);

    Sanctum::actingAs($this->engStaff);
    $this->getJson('/api/v1/app/kendala')->assertOk()->assertJsonPath('issues.0.id', $issue->id)->assertJsonPath('issues.0.for_me', true)->assertJsonPath('new_for_me', 1);
    $this->getJson("/api/v1/app/kendala/{$issue->id}")->assertOk()->assertJsonPath('issue.can.claim', true)->assertJsonPath('issue.can.work', false);

    $this->postJson("/api/v1/app/kendala/{$issue->id}/ambil")->assertOk();
    $this->postJson("/api/v1/app/kendala/{$issue->id}/status", ['status' => 'pending'])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->post("/api/v1/app/kendala/{$issue->id}/status", ['status' => 'done', 'body' => 'Freon diisi', 'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)]], ['Accept' => 'application/json'])->assertOk();

    $detail = $this->getJson("/api/v1/app/kendala/{$issue->id}")->assertOk()->assertJsonPath('issue.status', 'selesai')->assertJsonPath('issue.can.work', false);
    $photo = collect($detail->json('issue.updates'))->firstWhere('kind', 'selesai')['photos'][0]['id'];
    $this->get("/api/v1/app/kendala/{$issue->id}/foto/{$photo}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->getJson('/api/v1/app/kendala?status=selesai')->assertJsonPath('issues.0.id', $issue->id);

    // Yang kedua tidak bisa mengambil kendala yang sudah diambil.
    $other = RoomIssue::create(['room_id' => $this->room->id, 'source' => 'staff', 'description' => 'Lampu', 'priority' => 'biasa',
        'reported_by' => $this->foStaff->id, 'assigned_department_id' => $this->eng->id, 'status' => 'baru']);
    $this->postJson("/api/v1/app/kendala/{$other->id}/ambil")->assertOk();
    Sanctum::actingAs(appUserOf($this->eng));
    $this->postJson("/api/v1/app/kendala/{$other->id}/ambil")->assertForbidden();
});

it('hides issues of other departments and the whole menu when the feature is off', function () {
    $issue = RoomIssue::create(['room_id' => $this->room->id, 'source' => 'tamu', 'description' => 'AC', 'priority' => 'biasa',
        'reported_by' => $this->foStaff->id, 'assigned_department_id' => $this->eng->id, 'status' => 'baru']);

    Sanctum::actingAs(appUserOf($this->hk));
    $this->getJson("/api/v1/app/kendala/{$issue->id}")->assertForbidden();
    $this->getJson('/api/v1/app/kendala')->assertOk()->assertJsonCount(0, 'issues');
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.kendala', true);

    app(ChecklistSettings::class)->put(ChecklistSettings::featureSettingKey('kendala'), '0');
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.kendala', false);
    $this->getJson('/api/v1/app/kendala')->assertForbidden();
});

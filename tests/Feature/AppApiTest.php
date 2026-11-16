<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

// API aplikasi Android (/api/v1/app): login token, checklist milik sendiri, simpan lewat controller web yang sama.
beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    Task::factory()->for($this->gsa)->count(2)->create();
    $this->budi = Employee::factory()->for($this->fo)->create(['username' => 'budi']);
    $this->staff = User::factory()->role(Role::Staff)->create(['employee_id' => $this->budi->id]);
});

function appLogin(string $username = 'budi', string $pin = '482913')
{
    return test()->postJson('/api/v1/app/login', ['username' => $username, 'pin' => $pin, 'device' => 'Pixel']);
}

function appToken(): string
{
    attendanceAccepts(test()->budi);

    return appLogin()->assertOk()->json('token');
}

it('logs in with the attendance PIN and returns a device token without storing the PIN', function () {
    attendanceAccepts($this->budi);

    $response = appLogin()->assertOk()->assertJsonPath('user.name', $this->budi->name)->assertJsonPath('user.role', 'staff');

    $token = PersonalAccessToken::sole();
    expect($token->name)->toBe('Pixel')->and($token->tokenable_id)->toBe($this->staff->id)
        ->and($response->json('token'))->toBeString()->not->toContain('482913');
});

it('refuses wrong PINs with the same message as the web form and locks after five failures', function () {
    attendanceRefuses('invalid_credentials');

    foreach (range(1, 5) as $i) {
        appLogin()->assertStatus(422)->assertJsonPath('message', 'Username atau PIN/password tidak sesuai.');
    }
    appLogin()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Terlalu banyak percobaan login'));
    expect(PersonalAccessToken::count())->toBe(0);
});

it('refuses employees without app access', function () {
    $other = Employee::factory()->for($this->fo)->create(['username' => 'tamu']);
    attendanceAccepts($other);

    appLogin('tamu')->assertStatus(422)->assertJsonPath('message', 'Anda belum memiliki akses ke aplikasi ini. Hubungi HR / Administrator.');
});

it('lists only the staff member\'s own recent checklists', function () {
    $token = appToken();
    $mine = makeChecklist($this->budi, $this->gsa, null, null);
    makeChecklist($this->budi, $this->gsa, CarbonImmutable::today()->subDays(10), null);
    makeChecklist(Employee::factory()->for($this->fo)->create(), $this->gsa, null, null);

    $this->withToken($token)->getJson('/api/v1/app/checklists')->assertOk()
        ->assertJsonCount(1, 'checklists')
        ->assertJsonPath('checklists.0.id', $mine->id)
        ->assertJsonPath('checklists.0.can_edit', true)
        ->assertJsonPath('checklists.0.progress.total', 2);
});

it('shows a checklist with tasks and parameters, and hides other people\'s checklists', function () {
    $token = appToken();
    $mine = makeChecklist($this->budi, $this->gsa, null, null);
    $theirs = makeChecklist(Employee::factory()->for($this->fo)->create(), $this->gsa, null, null);

    $this->withToken($token)->getJson("/api/v1/app/checklists/{$mine->id}")->assertOk()
        ->assertJsonCount(2, 'checklist.items')
        ->assertJsonPath('checklist.items.0.parameters.0.type', 'status');
    $this->withToken($token)->getJson("/api/v1/app/checklists/{$theirs->id}")->assertForbidden();
});

it('saves task answers, notes and photos through the same rules as the web', function () {
    Storage::fake('local');
    $token = appToken();
    $checklist = makeChecklist($this->budi, $this->gsa, null, null);
    $item = $checklist->items->first();
    $status = $item->parameters()->first();

    $this->withToken($token)->patchJson("/api/v1/app/checklists/{$checklist->id}/items/{$item->id}", ['parameters' => [$status->id => 'pending'], 'note' => 'Lampu mati'])
        ->assertOk()->assertJsonPath('progress.pending', 1);
    expect($item->refresh())->status->value->toBe('pending')->note->toBe('Lampu mati');

    $this->withToken($token)->patchJson("/api/v1/app/checklists/{$checklist->id}", ['note' => 'Shift pagi'])->assertOk();
    expect($checklist->refresh()->note)->toBe('Shift pagi');

    $this->withToken($token)->post("/api/v1/app/checklists/{$checklist->id}/items/{$item->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
        ->assertCreated();
    $photo = $item->photos()->sole();
    $this->withToken($token)->get("/api/v1/app/checklists/{$checklist->id}/photos/{$photo->id}")->assertOk();
});

it('rejects changes after the checklist is reviewed', function () {
    $token = appToken();
    $checklist = makeChecklist($this->budi, $this->gsa);
    $checklist->forceFill(['status' => 'reviewed'])->save();
    $item = $checklist->items->first();

    $this->withToken($token)->patchJson("/api/v1/app/checklists/{$checklist->id}/items/{$item->id}", ['status' => 'pending'])->assertForbidden();
});

it('ends the token after the idle limit, after logout, or when access is revoked', function () {
    $token = appToken();
    $this->withToken($token)->getJson('/api/v1/app/me')->assertOk();

    $this->travel(config('session.lifetime') + 1)->minutes();
    nextRequest()->withToken($token)->getJson('/api/v1/app/me')->assertUnauthorized();
    $this->travelBack();

    $token = appToken();
    nextRequest()->withToken($token)->postJson('/api/v1/app/logout')->assertOk();
    nextRequest()->withToken($token)->getJson('/api/v1/app/me')->assertUnauthorized();

    $token = appToken();
    $this->staff->update(['is_active' => false]);
    nextRequest()->withToken($token)->getJson('/api/v1/app/me')->assertUnauthorized();
    expect(PersonalAccessToken::findToken($token))->toBeNull();
});

it('refuses tokens past the absolute session limit even when used', function () {
    $token = appToken();
    foreach (range(1, 3) as $i) {
        $this->travel(5)->hours();
        nextRequest()->withToken($token)->getJson('/api/v1/app/me');
    }

    nextRequest()->withToken($token)->getJson('/api/v1/app/me')->assertUnauthorized();
});

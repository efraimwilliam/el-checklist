<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\ChatThread;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;
use App\Push\AppPushSender;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// Obrolan di aplikasi Android (/api/v1/app/obrolan): pesan sebagai data, kirim lewat controller web, notifikasi ke HP lawan bicara.
beforeEach(function () {
    $dept = Department::factory()->create(['name' => 'Engineering']);
    $make = fn () => User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($dept)->create()->id]);
    $this->ani = $make();
    $this->budi = $make();
    $this->budi->createToken('HP')->accessToken->forceFill(['fcm_token' => 'hp-budi'])->save();
    $this->fake = new FakeAppPushSender;
    app()->instance(AppPushSender::class, $this->fake);
    app(ChecklistSettings::class)->put('chat_enabled', '1');
    Cache::flush();
});

it('opens a direct chat, sends text and photo, marks read, and notifies the other phone', function () {
    Storage::fake('local');
    Sanctum::actingAs($this->ani);
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.obrolan', true);
    $this->getJson('/api/v1/app/obrolan/kontak')->assertOk()->assertJsonPath('people.0.id', $this->budi->id);

    $thread = $this->postJson("/api/v1/app/obrolan/pribadi/{$this->budi->id}")->assertOk()->json('id');
    expect($this->postJson("/api/v1/app/obrolan/pribadi/{$this->budi->id}")->json('id'))->toBe($thread);

    $this->postJson("/api/v1/app/obrolan/{$thread}/pesan", ['body' => 'Lampu kamar 305 sudah diganti?'])->assertOk();
    $this->post("/api/v1/app/obrolan/{$thread}/pesan", ['photo' => UploadedFile::fake()->image('a.jpg', 400, 300)], ['Accept' => 'application/json'])->assertOk();
    expect($this->fake->sent)->toHaveCount(2)->and($this->fake->sent[0]['token'])->toBe('hp-budi')
        ->and($this->fake->sent[0]['message']['data'])->toBe(['type' => 'obrolan', 'id' => (string) $thread])
        ->and(json_encode($this->fake->sent))->not->toContain('Lampu'); // isi pesan tidak tampil di layar kunci

    Sanctum::actingAs($this->budi);
    $this->getJson('/api/v1/app/obrolan')->assertOk()->assertJsonPath('threads.0.id', $thread)->assertJsonPath('threads.0.unread', 2)->assertJsonPath('threads.0.title', $this->ani->name());
    $page = $this->getJson("/api/v1/app/obrolan/{$thread}/pesan")->assertOk()->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.body', 'Lampu kamar 305 sudah diganti?')->assertJsonPath('messages.0.mine', false)->assertJsonPath('messages.1.photo', true);
    $this->get("/api/v1/app/obrolan/{$thread}/foto/".$page->json('messages.1.id'))->assertOk();
    $this->getJson('/api/v1/app/obrolan')->assertJsonPath('threads.0.unread', 0);

    // Polling hanya mengembalikan yang lebih baru; hapus pesan oleh pengirimnya terlihat sebagai `deleted`.
    $this->getJson("/api/v1/app/obrolan/{$thread}/pesan?after=".$page->json('messages.1.id'))->assertJsonCount(0, 'messages');
    Sanctum::actingAs($this->ani);
    $this->deleteJson("/api/v1/app/obrolan/{$thread}/pesan/".$page->json('messages.0.id'))->assertOk();
    $this->getJson("/api/v1/app/obrolan/{$thread}/pesan?after=".$page->json('messages.1.id'))->assertJsonPath('deleted.0', $page->json('messages.0.id'));
});

it('keeps other people out of a private chat and closes everything when chat is off', function () {
    $thread = ChatThread::create(['direct_key' => ChatThread::directKey($this->ani->id, $this->budi->id), 'created_by' => $this->ani->id]);
    $thread->participants()->createMany([['user_id' => $this->ani->id], ['user_id' => $this->budi->id]]);

    Sanctum::actingAs(User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->create()->id]));
    $this->getJson("/api/v1/app/obrolan/{$thread->id}/pesan")->assertForbidden();
    $this->postJson("/api/v1/app/obrolan/{$thread->id}/pesan", ['body' => 'halo'])->assertForbidden();

    app(ChecklistSettings::class)->put('chat_enabled', '0');
    Sanctum::actingAs($this->ani);
    $this->getJson('/api/v1/app/obrolan')->assertForbidden();
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.obrolan', false);
});

it('shows typing, replies, and blue ticks like WhatsApp', function () {
    Sanctum::actingAs($this->ani);
    $thread = $this->postJson("/api/v1/app/obrolan/pribadi/{$this->budi->id}")->json('id');
    $this->postJson("/api/v1/app/obrolan/{$thread}/pesan", ['body' => 'AC kamar 305?'])->assertOk();
    $first = $this->getJson("/api/v1/app/obrolan/{$thread}/pesan")->json('messages.0.id');
    $this->getJson('/api/v1/app/obrolan')->assertJsonPath('threads.0.last_mine', true)->assertJsonPath('threads.0.last_read', false)->assertJsonPath('threads.0.group', false);

    Sanctum::actingAs($this->budi);
    $this->postJson("/api/v1/app/obrolan/{$thread}/mengetik")->assertOk();
    $this->postJson("/api/v1/app/obrolan/{$thread}/pesan", ['body' => 'Sudah dicek', 'reply_to' => $first])->assertOk();

    Sanctum::actingAs($this->ani);
    $page = $this->getJson("/api/v1/app/obrolan/{$thread}/pesan")->assertJsonPath('typing', [])  // mengetik berhenti saat pesan terkirim
        ->assertJsonPath('messages.1.reply.text', 'AC kamar 305?')->assertJsonPath('thread.title', $this->budi->name());
    expect($page->json('read_upto'))->toBeGreaterThanOrEqual($first);
    $this->getJson('/api/v1/app/obrolan')->assertJsonPath('threads.0.last_mine', false);

    Sanctum::actingAs($this->budi);
    $this->postJson("/api/v1/app/obrolan/{$thread}/mengetik")->assertOk();
    Sanctum::actingAs($this->ani);
    $this->getJson("/api/v1/app/obrolan/{$thread}/pesan")->assertJsonPath('typing', [$this->budi->name()]);
});

it('marks issue cards the reader cannot open and reports tab badges', function () {
    $acc = Department::factory()->create(['name' => 'Accounting']);
    $eng = $this->ani->employee->department;
    $room = Room::create(['number' => '305', 'floor' => '3', 'sort' => 1]);
    $issue = RoomIssue::create(['room_id' => $room->id, 'source' => 'staff', 'description' => 'AC', 'priority' => 'biasa',
        'reported_by' => $this->budi->id, 'assigned_department_id' => $eng->id, 'status' => 'baru']);
    $thread = ChatThread::create(['subject' => 'Grup', 'created_by' => $this->budi->id]);
    $outsider = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($acc)->create()->id]);
    $thread->participants()->createMany([['user_id' => $this->ani->id], ['user_id' => $this->budi->id], ['user_id' => $outsider->id]]);
    $thread->messages()->create(['user_id' => $this->budi->id, 'type' => 'text', 'room_issue_id' => $issue->id]);

    $open = fn ($user) => (function () use ($user, $thread) {
        Sanctum::actingAs($user);

        return $this->getJson("/api/v1/app/obrolan/{$thread->id}/pesan")->json('messages.0.card.can_open');
    })();
    // Angka dulu (membuka percakapan menandainya terbaca).
    Sanctum::actingAs($this->ani);
    $this->getJson('/api/v1/app/badges')->assertOk()->assertJsonPath('chat', 1)->assertJsonPath('kendala', 1)->assertJsonPath('permintaan', 0);
    Sanctum::actingAs($outsider);
    $this->getJson('/api/v1/app/badges')->assertJsonPath('kendala', 0);

    expect($open($this->ani))->toBeTrue()->and($open($outsider))->toBeFalse();
});

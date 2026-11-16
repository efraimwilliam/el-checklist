<?php

use App\Chat\QuickEntry;
use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GuestRequest;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;

/** Ganti akun di tengah tes: keluar dulu (halaman login menolak yang masih masuk), lalu masuk sebagai pengguna lain. */
function switchTo(User $user): void
{
    test()->post(route('logout'));
    app('auth')->forgetGuards();
    loggedIn($user);
}

beforeEach(function () {
    app(ChecklistSettings::class)->put('chat_enabled', '1');
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->eng = Department::factory()->create(['name' => 'Engineering']);
    $staff = fn (Department $department) => User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
    $this->foStaff = $staff($this->fo);
    $this->hkStaff = $staff($this->hk);
    $this->engStaff = $staff($this->eng);
    Room::create(['number' => '1206', 'sort' => 1]);
    $this->thread = ChatThread::create(['subject' => 'Room Division', 'created_by' => $this->foStaff->id]);
    foreach ([$this->foStaff, $this->hkStaff, $this->engStaff] as $user) {
        ChatParticipant::create(['chat_thread_id' => $this->thread->id, 'user_id' => $user->id]);
    }
});

it('reads short replies as take or done, and nothing else', function () {
    $quick = app(QuickEntry::class);

    foreach (['copy', 'Ok', 'otw ya *eka', 'siap pak 👍', 'on the way!', 'copy team'] as $text) {
        expect($quick->replyAction($text))->toBe('claim', $text);
    }
    foreach (['done', 'Sudah', 'ok done', 'udah ya pak', 'selesai 🙏'] as $text) {
        expect($quick->replyAction($text))->toBe('done', $text);
    }
    foreach (['ok nanti saya cek dulu kamarnya', 'kamar 1206 belum', 'terima kasih', '👍', 'copy, tapi handuknya habis'] as $text) {
        expect($quick->replyAction($text))->toBeNull($text);
    }
});

it('takes every request from a typed message when someone replies "copy" to it, but only as a reply', function () {
    loggedIn($this->foStaff);
    $this->postJson(route('chat.send', $this->thread), ['body' => '1206 req 2 mineral water + 1 towel', 'catat' => 1])->assertOk();
    $original = ChatMessage::whereNotNull('guest_request_id')->whereNotNull('body')->firstOrFail();
    expect(GuestRequest::where('status', GuestRequest::STATUS_NEW)->count())->toBe(2);

    switchTo($this->hkStaff);
    // Bukan balasan: pesan biasa, tidak ada yang diambil.
    $this->postJson(route('chat.send', $this->thread), ['body' => 'copy', 'balas_aksi' => 1])->assertOk();
    expect(GuestRequest::whereNotNull('claimed_by')->exists())->toBeFalse();

    // Pratinjau saat membalas, lalu kirim: dua permintaan diambil, catatan sistem untuk semua, kartu terbarui untuk pengirim.
    $this->postJson(route('chat.quick', $this->thread), ['text' => 'copy pak', 'reply_to' => $original->id])
        ->assertJson(['type' => 'reply_action', 'action' => 'claim'])->assertJsonCount(2, 'items');
    $response = $this->postJson(route('chat.send', $this->thread), ['body' => 'copy pak', 'reply_to' => $original->id, 'balas_aksi' => 1])->assertOk();
    expect(GuestRequest::where(['status' => GuestRequest::STATUS_CLAIMED, 'claimed_by' => $this->hkStaff->id])->count())->toBe(2)
        ->and(ChatMessage::where('type', 'system')->latest('id')->value('body'))->toContain('mengambil')->toContain('Kamar 1206')
        ->and($response->json('changed'))->toHaveCount(2);

    // Lalu "done" sebagai balasan = Selesai.
    $this->postJson(route('chat.send', $this->thread), ['body' => 'done', 'reply_to' => $original->id, 'balas_aksi' => 1])->assertOk();
    expect(GuestRequest::where(['status' => GuestRequest::STATUS_DONE, 'completed_by' => $this->hkStaff->id])->count())->toBe(2)
        ->and(ChatMessage::where('type', 'system')->latest('id')->value('body'))->toContain('menyelesaikan');
});

it('follows the same rights as the buttons: no take for other departments, and × sends a plain reply', function () {
    $request = GuestRequest::create(['room_id' => Room::first()->id, 'kind' => 'towel', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foStaff->id,
        'assigned_department_id' => $this->hk->id, 'status' => GuestRequest::STATUS_NEW]);
    $card = ChatMessage::create(['chat_thread_id' => $this->thread->id, 'user_id' => $this->foStaff->id, 'type' => 'text', 'guest_request_id' => $request->id]);

    loggedIn($this->engStaff);
    $this->postJson(route('chat.quick', $this->thread), ['text' => 'otw', 'reply_to' => $card->id])->assertJson(['type' => null]);
    $this->postJson(route('chat.send', $this->thread), ['body' => 'otw', 'reply_to' => $card->id, 'balas_aksi' => 1])->assertOk();
    expect($request->fresh()->claimed_by)->toBeNull();

    switchTo($this->hkStaff);
    $this->postJson(route('chat.send', $this->thread), ['body' => 'otw', 'reply_to' => $card->id])->assertOk(); // tanpa balas_aksi (× di pratinjau)
    expect($request->fresh()->claimed_by)->toBeNull();
});

it('takes and finishes an issue by replying to its card, logging both steps', function () {
    $category = IssueCategory::firstOrCreate(['name' => 'AC'], ['department_id' => $this->eng->id]);
    $issue = RoomIssue::create(['room_id' => Room::first()->id, 'category_id' => $category->id, 'description' => 'AC tidak dingin', 'source' => 'tamu',
        'priority' => 'biasa', 'reported_by' => $this->foStaff->id, 'assigned_department_id' => $this->eng->id, 'status' => RoomIssue::STATUS_NEW]);
    $card = ChatMessage::create(['chat_thread_id' => $this->thread->id, 'user_id' => $this->foStaff->id, 'type' => 'text', 'room_issue_id' => $issue->id]);

    loggedIn($this->engStaff);
    $this->postJson(route('chat.send', $this->thread), ['body' => 'sudah', 'reply_to' => $card->id, 'balas_aksi' => 1])->assertOk();

    $issue->refresh();
    expect($issue->status)->toBe(RoomIssue::STATUS_DONE)->and($issue->claimed_by)->toBe($this->engStaff->id)->and($issue->completed_by)->toBe($this->engStaff->id)
        ->and($issue->updates()->pluck('kind')->all())->toBe(['ambil', 'selesai']);
});

it('logs an issue straight from a typed message when confirmed (Shift+Enter), and merges a similar open one', function () {
    $category = IssueCategory::firstOrCreate(['name' => 'AC'], ['department_id' => $this->eng->id]);
    $category->update(['department_id' => $this->eng->id]);
    \App\Models\QuickTerm::firstOrCreate(['group' => 'issue', 'target' => (string) $category->id, 'term' => 'ac']);
    loggedIn($this->foStaff);

    // Tanpa catat_kendala = pesan biasa saja.
    $this->postJson(route('chat.send', $this->thread), ['body' => '1206 ac tidak dingin'])->assertOk();
    expect(RoomIssue::count())->toBe(0);

    $response = $this->postJson(route('chat.send', $this->thread), ['body' => '1206 ac tidak dingin, tamu di kamar', 'catat_kendala' => 1])->assertOk();
    $issue = RoomIssue::firstOrFail();
    expect($issue->room->number)->toBe('1206')->and($issue->category_id)->toBe($category->id)->and($issue->assigned_department_id)->toBe($this->eng->id)
        ->and($issue->priority)->toBe('mendesak')->and($issue->source)->toBe('staff')->and($issue->reported_by)->toBe($this->foStaff->id)
        ->and($issue->status)->toBe(RoomIssue::STATUS_NEW)
        ->and(ChatMessage::where('room_issue_id', $issue->id)->whereNotNull('body')->exists())->toBeTrue();
    expect($response->json('messages.0.html'))->toContain('1206');

    // Kendala serupa yang belum selesai: laporan tambahan, bukan kendala ganda.
    $this->postJson(route('chat.send', $this->thread), ['body' => '1206 ac masih mati', 'catat_kendala' => 1])->assertOk();
    expect(RoomIssue::count())->toBe(1)->and($issue->updates()->where('kind', 'laporan')->count())->toBe(1);

    // Pesan yang bukan kendala tidak membuat apa pun.
    $this->postJson(route('chat.send', $this->thread), ['body' => 'selamat pagi semua', 'catat_kendala' => 1])->assertOk();
    expect(RoomIssue::count())->toBe(1);
});

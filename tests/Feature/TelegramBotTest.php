<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GuestRequest;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\TelegramCard;
use App\Models\User;
use App\Telegram\TelegramHandler;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const TG_GROUP = -100123;

beforeEach(function () {
    config(['services.telegram' => ['token' => 'T0KEN', 'secret' => 'rahasia', 'username' => 'el_bot', 'chat_id' => TG_GROUP]]);
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900, 'chat' => ['id' => TG_GROUP]]])]);

    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->room = Room::create(['number' => '1206', 'sort' => 1]);
    $this->foUser = User::factory()->role(Role::Staff)->create(['telegram_id' => 111, 'employee_id' => Employee::factory()->for($this->fo)->create(['name' => 'Eka FO'])->id]);
    $this->hkUser = User::factory()->role(Role::Staff)->create(['telegram_id' => 222, 'employee_id' => Employee::factory()->for($this->hk)->create(['name' => 'Komang HK'])->id]);
});

/** @return list<array{method: string, data: array}> permintaan ke Telegram sejak awal tes */
function tgCalls(): array
{
    return Http::recorded()->map(fn (array $pair) => ['method' => basename($pair[0]->url()), 'data' => $pair[0]->data()])->values()->all();
}

/** Jalankan pekerjaan `defer` (di luar siklus HTTP tes tidak berjalan sendiri). */
function tgFlush(): void
{
    app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
}

function tgMessage(int $from, string $text, array $extra = [], int $chat = TG_GROUP): array
{
    return ['update_id' => 1, 'message' => ['message_id' => 500, 'from' => ['id' => $from], 'chat' => ['id' => $chat, 'type' => $chat > 0 ? 'private' : 'supergroup'], 'text' => $text] + $extra];
}

function tgClick(int $from, string $data, int $chat = TG_GROUP, int $message = 900): array
{
    return ['update_id' => 2, 'callback_query' => ['id' => 'cb1', 'from' => ['id' => $from], 'data' => $data, 'message' => ['message_id' => $message, 'chat' => ['id' => $chat]]]];
}

it('only accepts the webhook with the secret header', function () {
    $this->postJson('/api/telegram/webhook', tgMessage(111, 'halo'))->assertNotFound();
    $this->postJson('/api/telegram/webhook', tgMessage(111, 'halo'), ['X-Telegram-Bot-Api-Secret-Token' => 'salah'])->assertNotFound();
    $this->postJson('/api/telegram/webhook', tgMessage(111, 'halo'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    config(['services.telegram.token' => null]);
    $this->postJson('/api/telegram/webhook', tgMessage(111, 'halo'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertNotFound();
});

it('links a Telegram account with a one-time code from the profile page', function () {
    $user = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($this->fo)->create()->id]);
    loggedIn($user);

    $this->post(route('telegram.code'))->assertRedirect(route('profile.edit'));
    $code = session('telegram_code');
    expect($code)->toHaveLength(10);

    app(TelegramHandler::class)->handle(tgMessage(333, '/start '.$code, [], 333));
    expect($user->refresh()->telegram_id)->toBe(333);
    // Kode sekali pakai.
    app(TelegramHandler::class)->handle(tgMessage(444, '/start '.$code, [], 444));
    expect(User::where('telegram_id', 444)->exists())->toBeFalse();

    $this->delete(route('telegram.unlink'))->assertRedirect();
    expect($user->refresh()->telegram_id)->toBeNull();
});

it('records a free-typed request, posts one card to the group with buttons, and keeps the origin message for replies', function () {
    $this->postJson('/api/telegram/webhook', tgMessage(111, '1206 req 2 mineral water *eka'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();

    $request = GuestRequest::firstOrFail();
    expect($request->kind)->toBe('mineral_water')->and($request->quantity)->toBe(2)->and($request->requested_by)->toBe($this->foUser->id)->and($request->source)->toBe('chat');
    $send = collect(tgCalls())->firstWhere('method', 'sendMessage');
    expect($send['data']['chat_id'])->toBe(TG_GROUP)->and($send['data']['text'])->toContain('Kamar 1206')->toContain('2× Air mineral')
        ->and(json_encode($send['data']['reply_markup']))->toContain('rq:claim:'.$request->id);
    expect(TelegramCard::where(['guest_request_id' => $request->id, 'origin' => false, 'message_id' => 900])->exists())->toBeTrue()
        ->and(TelegramCard::where(['guest_request_id' => $request->id, 'origin' => true, 'message_id' => 500])->exists())->toBeTrue();
});

it('ignores unlinked users in groups, warns about unknown rooms, and does not read plain chatter', function () {
    $this->postJson('/api/telegram/webhook', tgMessage(999, '1206 req 2 mineral water'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    expect(GuestRequest::count())->toBe(0)->and(tgCalls())->toBe([]);

    $this->postJson('/api/telegram/webhook', tgMessage(111, '9999 req 2 mineral water'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia']);
    expect(GuestRequest::count())->toBe(0)->and(json_encode(tgCalls()))->toContain('9999');

    $this->postJson('/api/telegram/webhook', tgMessage(111, 'selamat pagi semua'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia']);
    expect(GuestRequest::count())->toBe(0);
});

it('lets Housekeeping take and finish the request by tapping the buttons, updating the card', function () {
    $request = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foUser->id, 'assigned_department_id' => $this->hk->id]);
    TelegramCard::create(['chat_id' => TG_GROUP, 'message_id' => 900, 'guest_request_id' => $request->id]);

    app(TelegramHandler::class)->handle(tgClick(222, 'rq:claim:'.$request->id));
    expect($request->refresh()->status)->toBe('diambil')->and($request->claimed_by)->toBe($this->hkUser->id);

    app(TelegramHandler::class)->handle(tgClick(222, 'rq:done:'.$request->id));
    expect($request->refresh()->status)->toBe('selesai')->and($request->completed_by)->toBe($this->hkUser->id);
    tgFlush();

    $edits = collect(tgCalls())->where('method', 'editMessageText')->values();
    expect($edits)->not->toBeEmpty()->and($edits->last()['data']['text'])->toContain('Komang HK')->and($edits->last()['data']['reply_markup']['inline_keyboard'])->toBe([]);
});

it('respects permissions: an outsider cannot take it, and only the requester can cancel', function () {
    $request = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foUser->id, 'assigned_department_id' => $this->hk->id]);
    $eng = User::factory()->role(Role::Staff)->create(['telegram_id' => 333, 'employee_id' => Employee::factory()->for(Department::factory()->create(['name' => 'Engineering']))->create()->id]);

    app(TelegramHandler::class)->handle(tgClick(333, 'rq:claim:'.$request->id));
    expect($request->refresh()->status)->toBe('baru');
    app(TelegramHandler::class)->handle(tgClick(222, 'rq:del:'.$request->id));
    expect(GuestRequest::count())->toBe(1);
    app(TelegramHandler::class)->handle(tgClick(111, 'rq:del:'.$request->id));
    expect(GuestRequest::count())->toBe(0);
});

it('treats a reply "copy" / "done" on the original message or the card as Saya ambil / Selesai', function () {
    $this->postJson('/api/telegram/webhook', tgMessage(111, '1206 req towel'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia']);
    $request = GuestRequest::firstOrFail();

    // HK membalas pesan asli FO dengan "copy", lalu kartu bot dengan "done".
    app(TelegramHandler::class)->handle(tgMessage(222, 'copy', ['message_id' => 501, 'reply_to_message' => ['message_id' => 500]]));
    expect($request->refresh()->status)->toBe('diambil')->and($request->claimed_by)->toBe($this->hkUser->id);
    app(TelegramHandler::class)->handle(tgMessage(222, 'done pak', ['message_id' => 502, 'reply_to_message' => ['message_id' => 900]]));
    expect($request->refresh()->status)->toBe('selesai');

    // Kalimat biasa bukan aksi.
    $other = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'pillow', 'quantity' => 1, 'priority' => 'biasa', 'requested_by' => $this->foUser->id, 'assigned_department_id' => $this->hk->id]);
    TelegramCard::create(['chat_id' => TG_GROUP, 'message_id' => 777, 'guest_request_id' => $other->id]);
    app(TelegramHandler::class)->handle(tgMessage(222, 'ok nanti saya cek dulu', ['reply_to_message' => ['message_id' => 777]]));
    expect($other->refresh()->status)->toBe('baru');
});

it('offers an issue for confirmation and records it only on the Catat kendala tap, by the sender only', function () {
    IssueCategory::where('name', 'AC')->update(['department_id' => $this->hk->id]);

    $this->postJson('/api/telegram/webhook', tgMessage(111, '1206 AC tidak dingin'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia']);
    expect(RoomIssue::count())->toBe(0);
    $buttons = json_encode(collect(tgCalls())->firstWhere('method', 'sendMessage')['data']['reply_markup']);
    preg_match('~ik:rec:([0-9a-f]+)~', $buttons, $m);

    app(TelegramHandler::class)->handle(tgClick(222, 'ik:rec:'.$m[1])); // orang lain tidak bisa
    expect(RoomIssue::count())->toBe(0);
    app(TelegramHandler::class)->handle(tgClick(111, 'ik:rec:'.$m[1]));
    $issue = RoomIssue::firstOrFail();
    expect($issue->room_id)->toBe($this->room->id)->and($issue->reported_by)->toBe($this->foUser->id)->and($issue->assigned_department_id)->toBe($this->hk->id);
});

it('refreshes the Telegram card when the request changes on the web', function () {
    loggedIn($this->hkUser); // masuk dulu: loginnya memalsukan Http sendiri, jadi palsu Telegram dipasang sesudahnya
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900, 'chat' => ['id' => TG_GROUP]]])]);

    $this->postJson('/api/telegram/webhook', tgMessage(111, '1206 req towel'), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia']);
    $request = GuestRequest::firstOrFail();

    $this->post(route('permintaan.claim', $request))->assertRedirect();

    $edit = collect(tgCalls())->where('method', 'editMessageText')->last();
    expect($edit['data']['message_id'])->toBe(900)->and($edit['data']['text'])->toContain('Diambil')->and($edit['data']['text'])->toContain('Komang HK');
});

it('polls Telegram for updates (no public URL needed), handles them, and moves the offset forward', function () {
    Cache::forget('telegram.poll.offset');
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [array_merge(tgMessage(111, '1206 req 2 mineral water *eka'), ['update_id' => 41])]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900, 'chat' => ['id' => TG_GROUP]]]),
    ]);

    $this->artisan('telegram:poll', ['--sekali' => true])->assertExitCode(0);

    $methods = array_column(tgCalls(), 'method');
    expect($methods)->toContain('deleteWebhook')->toContain('getUpdates')->toContain('sendMessage');
    expect(GuestRequest::firstOrFail()->kind)->toBe('mineral_water')->and(Cache::get('telegram.poll.offset'))->toBe(42);
    // Putaran berikutnya meminta update sesudah 41.
    $this->artisan('telegram:poll', ['--sekali' => true]);
    $offsets = collect(tgCalls())->where('method', 'getUpdates')->pluck('data.offset');
    expect($offsets->last())->toBe(42);
});

it('mengingatkan di kartu bila permintaan belum diambil 15 lalu 30 menit, sekali per tingkat', function () {
    $r = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'status' => 'baru', 'priority' => 'biasa', 'requested_by' => $this->foUser->id]);
    TelegramCard::create(['chat_id' => TG_GROUP, 'message_id' => 900, 'guest_request_id' => $r->id]);

    $this->artisan('telegram:eskalasi');
    expect(collect(tgCalls())->where('method', 'sendMessage'))->toHaveCount(0);

    $this->travel(16)->minutes();
    $this->artisan('telegram:eskalasi');
    $this->artisan('telegram:eskalasi');
    $sent = collect(tgCalls())->where('method', 'sendMessage');
    expect($sent)->toHaveCount(1)->and($sent->first()['data']['reply_parameters']['message_id'])->toBe(900);

    $this->travel(15)->minutes();
    $this->artisan('telegram:eskalasi');
    expect(collect(tgCalls())->where('method', 'sendMessage'))->toHaveCount(2);

    $r->update(['status' => 'diambil']);
    $this->travel(60)->minutes();
    $this->artisan('telegram:eskalasi');
    expect(collect(tgCalls())->where('method', 'sendMessage'))->toHaveCount(2);
});

it('perintah /daftar, /respon, /anggota', function () {
    $r = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'status' => 'diambil', 'priority' => 'biasa', 'requested_by' => $this->foUser->id, 'claimed_by' => $this->hkUser->id, 'claimed_at' => now()]);
    $send = fn (int $from, string $cmd) => $this->postJson('/api/telegram/webhook', tgMessage($from, $cmd), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia']);
    $texts = fn () => collect(tgCalls())->where('method', 'sendMessage')->pluck('data.text');

    $send(111, '/daftar@el_bot');
    $send(111, '/respon');
    $send(111, '/anggota'); // bukan HR / Admin: diabaikan
    expect($texts()->filter(fn ($t) => str_contains($t, 'Belum selesai')))->toHaveCount(1)
        ->and($texts()->filter(fn ($t) => str_contains($t, 'Permintaan hari ini')))->toHaveCount(1)
        ->and($texts()->filter(fn ($t) => str_contains($t, 'Sudah tertaut')))->toHaveCount(0);

    $this->foUser->update(['role' => Role::Hr]);
    $send(111, '/anggota');
    expect($texts()->filter(fn ($t) => str_contains($t, 'Sudah tertaut') && str_contains($t, 'Eka FO')))->toHaveCount(1);
});

it('pelapor tidak bisa mengambil permintaannya sendiri lewat tombol', function () {
    $r = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'status' => 'baru', 'priority' => 'biasa', 'requested_by' => $this->hkUser->id]);
    $this->postJson('/api/telegram/webhook', tgClick(222, 'rq:claim:'.$r->id), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    expect($r->fresh()->status)->toBe('baru');
});

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

it('mencatat barang tertinggal dari teks / foto berketerangan dan mengubah statusnya lewat balasan kartu', function () {
    $post = fn (array $body) => $this->postJson('/api/telegram/webhook', $body, ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();

    $post(tgMessage(222, '1206 dompet hitam tertinggal'));
    $post(tgMessage(222, '1206 charger tertinggal', ['photo' => [['file_id' => 'f1'], ['file_id' => 'f2']], 'message_id' => 501]));
    expect(\App\Models\LostItem::count())->toBe(2);
    $item = \App\Models\LostItem::first();
    expect($item->description)->toContain('dompet')->and($item->source)->toBe('telegram')->and($item->status)->toBe('disimpan');
    expect(collect(tgCalls())->where('method', 'sendMessage')->pluck('data.text')->join(' '))->toContain('🧳');

    $card = TelegramCard::where('lost_item_id', $item->id)->where('origin', true)->first();
    $reply = fn (string $text) => $post(tgMessage(111, $text, ['reply_to_message' => ['message_id' => $card->message_id]]));
    $reply('diambil');
    expect($item->fresh()->status)->toBe('disimpan'); // wajib nama penerima
    $reply('diambil Mr. Smith');
    expect($item->fresh())->status->toBe('diambil')->resolution_note->toBe('Mr. Smith');
});

it('memperingatkan HOD (30 / 60 / 120 menit) dan HOD menugaskan pegawai lewat tombol', function () {
    $hod = User::factory()->role(Role::Hod)->create(['telegram_id' => 333, 'employee_id' => Employee::factory()->for($this->hk)->create(['name' => 'Pak Wayan'])->id]);
    $hod->departments()->attach($this->hk);
    $r = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'status' => 'baru', 'priority' => 'biasa', 'requested_by' => $this->foUser->id, 'assigned_department_id' => $this->hk->id]);
    $dm = fn () => collect(tgCalls())->where('method', 'sendMessage')->filter(fn ($c) => ($c['data']['chat_id'] ?? null) === 333);

    $this->travel(31)->minutes();
    $this->artisan('telegram:eskalasi');
    $this->artisan('telegram:eskalasi');
    expect($dm())->toHaveCount(1)->and($dm()->first()['data']['text'])->toContain('Peringatan 1/3');

    $this->travel(30)->minutes();
    $this->artisan('telegram:eskalasi');
    expect($dm())->toHaveCount(2)->and($dm()->last()['data']['text'])->toContain('Peringatan 2/3');

    $click = fn (string $data) => $this->postJson('/api/telegram/webhook', tgClick(333, $data, 333), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    $click('as:rq:'.$r->id);
    expect(collect(tgCalls())->where('method', 'editMessageText')->last()['data']['reply_markup']['inline_keyboard'])->not->toBeEmpty();

    $click('as:rq:'.$r->id.':'.$this->hkUser->id);
    expect($r->fresh())->claimed_by->toBe($this->hkUser->id)->status->toBe('diambil')
        ->and(collect(tgCalls())->where('method', 'sendMessage')->filter(fn ($c) => ($c['data']['chat_id'] ?? null) === 222)->first()['data']['text'])->toContain('menugaskan Anda');

    // Pegawai biasa tidak boleh menugaskan.
    $other = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'status' => 'baru', 'priority' => 'biasa', 'requested_by' => $this->foUser->id, 'assigned_department_id' => $this->hk->id]);
    $this->postJson('/api/telegram/webhook', tgClick(222, 'as:rq:'.$other->id.':'.$this->hkUser->id, 222), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    expect($other->fresh()->claimed_by)->toBeNull();
});

it('mencoba lagi sekali bila Telegram membatasi kirim (429)', function () {
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::fake(['api.telegram.org/*' => Http::sequence()
        ->push(['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 1]], 429)
        ->push(['ok' => true, 'result' => ['message_id' => 7]])]);

    expect(app(\App\Telegram\TelegramApi::class)->send(TG_GROUP, 'halo'))->toMatchArray(['message_id' => 7]);
    Http::assertSentCount(2);
});

it('HR membuat tautan Telegram per pegawai: sekali pakai, tanpa login pegawai', function () {
    $newUser = User::factory()->role(Role::Staff)->create(['telegram_id' => null, 'employee_id' => Employee::factory()->for($this->hk)->create(['name' => 'Budi Baru'])->id]);
    $hr = User::factory()->role(Role::Hr)->create(['employee_id' => Employee::factory()->for($this->fo)->create()->id]);

    loggedIn($hr);
    $this->get(route('admin.telegram.index'))->assertOk()->assertSee('Budi Baru')->assertSee('t.me/el_bot?start=', false);
    $code = $newUser->fresh()->telegram_link_code;
    expect($code)->not->toBeNull();
    $this->get(route('admin.telegram.index')); // dibuka ulang: tautan yang sama dipakai
    expect($newUser->fresh()->telegram_link_code)->toBe($code);

    Http::swap(new \Illuminate\Http\Client\Factory(app('events'))); // loggedIn() memalsukan Http sendiri
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900, 'chat' => ['id' => TG_GROUP]]])]);
    $start = fn (int $from) => $this->postJson('/api/telegram/webhook', tgMessage($from, '/start '.$code, [], 777), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    $start(777);
    expect($newUser->fresh())->telegram_id->toBe(777)->telegram_link_code->toBeNull();

    $start(888); // tautan sudah dipakai
    expect(User::where('telegram_id', 888)->exists())->toBeFalse();
});

it('halaman Tautan Telegram hanya untuk HR / Administrator', function () {
    loggedIn($this->hkUser);
    $this->get(route('admin.telegram.index'))->assertForbidden();
});

it('foto yang membalas kartu kendala jadi bukti pekerjaan, hanya dari yang mengambil', function () {
    \Illuminate\Support\Facades\Storage::fake(config('checklist.photos.disk'));
    $image = imagecreatetruecolor(40, 30);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::fake([
        'api.telegram.org/file/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        'api.telegram.org/*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/a.jpg']]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901, 'chat' => ['id' => TG_GROUP]]]),
    ]);
    $issue = RoomIssue::create(['room_id' => $this->room->id, 'source' => 'staff', 'description' => 'AC', 'priority' => 'biasa', 'status' => 'dikerjakan', 'claimed_by' => $this->hkUser->id, 'claimed_at' => now(), 'assigned_department_id' => $this->hk->id]);
    TelegramCard::create(['chat_id' => TG_GROUP, 'message_id' => 950, 'room_issue_id' => $issue->id]);
    $send = fn (int $from, array $extra) => $this->postJson('/api/telegram/webhook', tgMessage($from, '', ['photo' => [['file_id' => 'a'], ['file_id' => 'b']], 'reply_to_message' => ['message_id' => 950]] + $extra), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();

    $send(111, []); // bukan yang mengambil
    expect($issue->updates()->count())->toBe(0);

    $send(222, ['caption' => 'AC sudah dibersihkan', 'media_group_id' => 'g1']);
    $send(222, ['media_group_id' => 'g1', 'message_id' => 502]);
    $update = $issue->updates()->latest('id')->first();
    expect($issue->updates()->count())->toBe(1)->and($update->body)->toBe('AC sudah dibersihkan')->and($update->photos)->toHaveCount(2);

    $send(222, ['caption' => 'done']);
    expect($issue->fresh()->status)->toBe('selesai');
});

it('mengingatkan pengambil lalu HOD bila sudah diambil tapi belum selesai (tidak di jam tenang, Pending dilewati)', function () {
    $this->travelTo(now()->setTime(10, 0));
    $hod = User::factory()->role(Role::Hod)->create(['telegram_id' => 333, 'employee_id' => Employee::factory()->for($this->hk)->create(['name' => 'Pak Wayan'])->id]);
    $hod->departments()->attach($this->hk);
    $r = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'status' => 'diambil', 'priority' => 'biasa', 'requested_by' => $this->foUser->id, 'assigned_department_id' => $this->hk->id, 'claimed_by' => $this->hkUser->id, 'claimed_at' => now()]);
    $pending = RoomIssue::create(['room_id' => $this->room->id, 'source' => 'staff', 'description' => 'AC', 'priority' => 'biasa', 'status' => 'tertunda', 'claimed_by' => $this->hkUser->id, 'claimed_at' => now()->subHours(9), 'assigned_department_id' => $this->hk->id]);
    $to = fn (int $chat) => collect(tgCalls())->where('method', 'sendMessage')->filter(fn ($c) => ($c['data']['chat_id'] ?? null) === $chat);

    $this->travel(61)->minutes();
    $this->artisan('telegram:eskalasi');
    $this->artisan('telegram:eskalasi');
    expect($to(222))->toHaveCount(1)->and($to(222)->first()['data']['text'])->toContain('Sudah selesai?')->and($to(333))->toHaveCount(0);

    $this->travel(120)->minutes(); // total 3 jam sejak diambil
    $this->artisan('telegram:eskalasi');
    expect($to(333))->toHaveCount(1)->and($to(333)->first()['data']['text'])->toContain('belum selesai');

    expect($pending->fresh()->claimed_reminders)->toBe(0); // Pending tidak diingatkan

    $r2 = GuestRequest::create(['room_id' => $this->room->id, 'kind' => 'towel', 'status' => 'diambil', 'priority' => 'biasa', 'requested_by' => $this->foUser->id, 'assigned_department_id' => $this->hk->id, 'claimed_by' => $this->hkUser->id, 'claimed_at' => now()]);
    $this->travelTo(now()->setTime(23, 0)->addDay()->subHours(0));
    $r2->update(['claimed_at' => now()->subHours(2)]);
    $before = $to(222)->count();
    $this->artisan('telegram:eskalasi');
    expect($to(222))->toHaveCount($before); // jam tenang
});

it('/chronology: tempel teks -> draft + pratinjau -> finalisasi dikirim ke grup', function () {
    $this->foUser->employee->update(['position_title' => 'GRO']);
    $post = fn (array $body) => $this->postJson('/api/telegram/webhook', $body, ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    $private = fn (string $text) => tgMessage(111, $text, [], 111);
    $sentTo = fn ($chat) => collect(tgCalls())->where('method', 'sendMessage')->filter(fn ($c) => ($c['data']['chat_id'] ?? null) === $chat);

    $post(tgMessage(111, '/chronology')); // di grup: ditolak
    expect(\App\Models\ChronologyReport::count())->toBe(0);

    $post($private('/chronology'));
    $post($private("Guest Name : Mr John Smith\nRoom Number : 1206\nNationality : Australia\nLength of stay : 02 November 2026 - 05 November 2026\nSource of Booking : Booking.com\n\n1. Tamu melapor AC mati.\n2. Teknisi memperbaiki."));
    $report = \App\Models\ChronologyReport::with(['guests', 'entries'])->sole();
    expect($report->status)->toBe('draft')->and($report->guests->first()->name)->toBe('Mr John Smith')->and($report->entries)->toHaveCount(2)->and($report->author_id)->toBe($this->foUser->id);
    expect($sentTo(111)->pluck('data.text')->join(' '))->toContain('Mr John Smith');

    $post(tgClick(222, 'cr:fin:'.$report->id, 222)); // bukan penulis
    expect($report->fresh()->status)->toBe('draft');

    $post(tgClick(111, 'cr:fin:'.$report->id, 111));
    expect($report->fresh()->status)->toBe('final')->and($sentTo(TG_GROUP)->pluck('data.text')->join(' '))->toContain('Mr John Smith');
});

it('/chronology menolak teks yang belum lengkap dan menunggu perbaikan', function () {
    $post = fn (string $text) => $this->postJson('/api/telegram/webhook', tgMessage(111, $text, [], 111), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    $post("/chronology\nhalo saja");
    expect(\App\Models\ChronologyReport::count())->toBe(0);
    expect(collect(tgCalls())->where('method', 'sendMessage')->pluck('data.text')->join(' '))->toContain('Belum terbaca');
});

it('/checklist: isi tugas dengan tombol, Pending dengan alasan', function () {
    $position = \App\Models\Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    \App\Models\Task::factory()->for($position)->create(['title' => 'Cek lobby', 'sort' => 1]);
    \App\Models\Task::factory()->for($position)->create(['title' => 'Cek kunci', 'sort' => 2]);
    $checklist = makeChecklist($this->foUser->employee, $position, null, null);
    $items = $checklist->items()->orderBy('sort')->get();
    $post = fn (array $body) => $this->postJson('/api/telegram/webhook', $body, ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia'])->assertOk();
    // makeChecklist() memalsukan Http sendiri: pasang kembali tiruan Telegram.
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900, 'chat' => ['id' => 111]]])]);

    $post(tgMessage(111, '/checklist', [], 111));
    expect(collect(tgCalls())->where('method', 'sendMessage')->pluck('data.text')->join(' '))->toContain('Cek lobby');

    $post(tgClick(111, 'cl:s:'.$checklist->id.':'.$items[0]->id.':done', 111));
    expect($items[0]->fresh()->status->value)->toBe('done');
    expect(collect(tgCalls())->where('method', 'editMessageText')->last()['data']['text'])->toContain('Cek kunci'); // pindah ke tugas berikutnya

    $post(tgClick(111, 'cl:s:'.$checklist->id.':'.$items[1]->id.':pending', 111));
    expect($items[1]->fresh()->status->value)->toBe('pending');
    $post(tgMessage(111, 'Kunci belum diserahkan', [], 111));
    expect($items[1]->fresh()->note)->toBe('Kunci belum diserahkan');

    // Orang lain tidak bisa mengubah checklist ini.
    $post(tgClick(222, 'cl:s:'.$checklist->id.':'.$items[0]->id.':pending', 222));
    expect($items[0]->fresh()->status->value)->toBe('done');
});

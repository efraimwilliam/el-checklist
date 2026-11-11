<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\ChatThread;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\ChatPushNotifier;
use App\Push\PushSender;
use App\Push\VapidKeys;
use Illuminate\Support\Facades\Cache;

function pushUser(Department $department, Role $role = Role::Staff): User
{
    return User::factory()->role($role)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
}

function pushOf(User $user, string $endpoint): PushSubscription
{
    return PushSubscription::create([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashOf($endpoint),
        'public_key' => 'BPublicKeyDummy', 'auth_token' => 'authDummy', 'content_encoding' => 'aes128gcm',
    ]);
}

function pushAs(User $user)
{
    test()->post(route('logout'));
    app('auth')->forgetGuards();

    return loggedIn($user);
}

beforeEach(function () {
    $this->dept = Department::factory()->create(['name' => 'Engineering']);
    $this->ani = pushUser($this->dept);
    $this->budi = pushUser($this->dept);
    $this->citra = pushUser($this->dept);
    $this->fake = new FakePushSender;
    app()->instance(PushSender::class, $this->fake);
    app(ChecklistSettings::class)->put('chat_enabled', '1');
    Cache::flush();
});

it('creates the VAPID keys once and exposes only the public key to pages', function () {
    $keys = VapidKeys::pair();
    expect($keys['publicKey'])->not->toBeEmpty()->and($keys['privateKey'])->not->toBeEmpty()->and(VapidKeys::pair())->toBe($keys);
    expect(file_exists(VapidKeys::path()))->toBeTrue()->and(substr(sprintf('%o', fileperms(VapidKeys::path())), -3))->toBe('600');

    pushAs($this->ani);
    $page = $this->get(route('checklists.index'))->assertOk();
    $page->assertSee($keys['publicKey'], false)->assertDontSee($keys['privateKey'], false);
    $page->assertSee('manifest.webmanifest', false)->assertSee("register('/sw.js'", false);
});

it('stores a subscription for the logged in user, moves it when another user turns it on, and removes it', function () {
    pushAs($this->ani);
    $body = ['endpoint' => 'https://push.example.com/send/abc123', 'keys' => ['p256dh' => 'BKeyOne', 'auth' => 'AuthOne'], 'contentEncoding' => 'aes128gcm'];

    $this->postJson(route('chat.push.subscribe'), $body)->assertOk()->assertJson(['subscribed' => true]);
    $this->postJson(route('chat.push.status'), ['endpoint' => $body['endpoint']])->assertOk()->assertJson(['subscribed' => true]);
    expect(PushSubscription::count())->toBe(1)->and(PushSubscription::first()->user_id)->toBe($this->ani->id);

    // Perangkat yang sama dinyalakan Budi: pindah ke Budi, Ani tidak lagi menerima di perangkat itu.
    pushAs($this->budi);
    $this->postJson(route('chat.push.status'), ['endpoint' => $body['endpoint']])->assertOk()->assertJson(['subscribed' => false]);
    $this->postJson(route('chat.push.subscribe'), $body)->assertOk();
    expect(PushSubscription::count())->toBe(1)->and(PushSubscription::first()->user_id)->toBe($this->budi->id);

    // Hanya pemiliknya yang bisa mematikan.
    pushAs($this->ani);
    $this->deleteJson(route('chat.push.unsubscribe'), ['endpoint' => $body['endpoint']])->assertOk();
    expect(PushSubscription::count())->toBe(1);
    pushAs($this->budi);
    $this->deleteJson(route('chat.push.unsubscribe'), ['endpoint' => $body['endpoint']])->assertOk();
    expect(PushSubscription::count())->toBe(0);
});

it('validates subscriptions and keeps only the newest devices per user', function () {
    pushAs($this->ani);
    $this->postJson(route('chat.push.subscribe'), ['endpoint' => 'http://insecure.example.com/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(422);
    $this->postJson(route('chat.push.subscribe'), ['endpoint' => 'https://push.example.com/x'])->assertStatus(422);

    foreach (range(1, 12) as $i) {
        $this->travel($i)->minutes();
        $this->postJson(route('chat.push.subscribe'), ['endpoint' => "https://push.example.com/device/{$i}", 'keys' => ['p256dh' => 'k', 'auth' => 'a']])->assertOk();
    }
    expect(PushSubscription::where('user_id', $this->ani->id)->count())->toBe(10)
        ->and(PushSubscription::where('endpoint', 'https://push.example.com/device/1')->exists())->toBeFalse()
        ->and(PushSubscription::where('endpoint', 'https://push.example.com/device/12')->exists())->toBeTrue();
});

it('blocks subscriptions when chat is switched off', function () {
    app(ChecklistSettings::class)->put('chat_enabled', '0');
    pushAs($this->ani);
    $this->postJson(route('chat.push.subscribe'), ['endpoint' => 'https://push.example.com/x', 'keys' => ['p256dh' => 'k', 'auth' => 'a']])->assertForbidden();
    $this->get(route('checklists.index'))->assertOk()->assertDontSee('window.__push', false);
});

it('pushes a short notification to the other participants only, without the message text', function () {
    pushOf($this->ani, 'https://push.example.com/ani');
    pushOf($this->budi, 'https://push.example.com/budi-1');
    pushOf($this->budi, 'https://push.example.com/budi-2');

    pushAs($this->ani);
    $this->post(route('chat.direct', $this->budi))->assertRedirect();
    $thread = ChatThread::firstOrFail();
    $this->postJson(route('chat.send', $thread), ['body' => 'Password wifi tamu: rahasia123'])->assertOk();

    $endpoints = collect($this->fake->sent)->pluck('endpoint')->all();
    expect($endpoints)->toEqualCanonicalizing(['https://push.example.com/budi-1', 'https://push.example.com/budi-2'])
        ->and($this->fake->sent[0]['payload'])->toMatchArray(['title' => $this->ani->name(), 'body' => 'Pesan baru', 'url' => '/obrolan/'.$thread->id, 'tag' => 'chat-'.$thread->id])
        ->and(json_encode($this->fake->sent))->not->toContain('rahasia123');
});

it('says who mentioned the recipient in a group and skips people who are reading the chat right now', function () {
    pushOf($this->budi, 'https://push.example.com/budi');
    pushOf($this->citra, 'https://push.example.com/citra');

    pushAs($this->ani);
    $this->post(route('chat.store'), ['subject' => 'Tim AC', 'users' => [$this->budi->id, $this->citra->id]])->assertRedirect();
    $thread = ChatThread::where('subject', 'Tim AC')->firstOrFail();
    expect($this->fake->sent)->toBe([]); // catatan sistem "membuat grup" tidak dikirim

    $this->postJson(route('chat.send', $thread), ['body' => 'Tolong @'.$this->budi->name().' cek'])->assertOk();
    $byEndpoint = collect($this->fake->sent)->keyBy('endpoint');
    expect($byEndpoint['https://push.example.com/budi']['payload'])->toMatchArray(['title' => 'Tim AC', 'body' => $this->ani->name().' menyebut Anda'])
        ->and($byEndpoint['https://push.example.com/citra']['payload']['body'])->toBe($this->ani->name().' mengirim pesan baru');

    // Citra sedang membuka obrolan (polling): tidak dikirimi.
    $this->fake->sent = [];
    ChatPushNotifier::markPresent($thread->id, $this->citra->id);
    $this->postJson(route('chat.send', $thread), ['body' => 'Satu lagi'])->assertOk();
    expect(collect($this->fake->sent)->pluck('endpoint')->all())->toBe(['https://push.example.com/budi']);

    // Membuka dan polling menandai hadir.
    Cache::flush();
    pushAs($this->citra);
    $this->getJson(route('chat.poll', $thread))->assertOk();
    expect(Cache::has(ChatPushNotifier::presenceKey($thread->id, $this->citra->id)))->toBeTrue();
});

it('drops subscriptions that the push service says are gone, keeps the ones that merely failed, and stays silent when chat is off', function () {
    pushOf($this->budi, 'https://push.example.com/gone');
    pushOf($this->budi, 'https://push.example.com/flaky');
    pushOf($this->budi, 'https://push.example.com/ok');
    $this->fake->results = ['https://push.example.com/gone' => PushSender::GONE, 'https://push.example.com/flaky' => PushSender::FAILED];

    pushAs($this->ani);
    $this->post(route('chat.direct', $this->budi))->assertRedirect();
    $thread = ChatThread::firstOrFail();
    $this->postJson(route('chat.send', $thread), ['body' => 'halo'])->assertOk();

    expect(PushSubscription::pluck('endpoint')->sort()->values()->all())->toBe(['https://push.example.com/flaky', 'https://push.example.com/ok'])
        ->and(PushSubscription::where('endpoint', 'https://push.example.com/ok')->first()->last_used_at)->not->toBeNull();

    $this->fake->sent = [];
    app(ChecklistSettings::class)->put('chat_enabled', '0');
    $message = $thread->messages()->latest('id')->first();
    expect(app(ChatPushNotifier::class)->notify($thread->id, $message->id))->toBe(0)->and($this->fake->sent)->toBe([]);
});

it('also notifies when a kendala, checklist or report is forwarded into a chat', function () {
    pushOf($this->budi, 'https://push.example.com/budi');
    $room = \App\Models\Room::create(['number' => '305', 'floor' => '3', 'type' => 'Deluxe', 'sort' => 1]);
    $category = \App\Models\IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->dept->id, 'sort' => 99]);
    $issue = \App\Models\RoomIssue::create([
        'room_id' => $room->id, 'category_id' => $category->id, 'source' => 'tamu', 'description' => 'AC bocor', 'priority' => 'biasa',
        'reported_by' => $this->ani->id, 'assigned_department_id' => $this->dept->id, 'status' => 'baru',
    ]);

    pushAs($this->ani);
    $this->post(route('chat.share-issue', $issue), ['people' => [$this->budi->id]])->assertRedirect();
    expect($this->fake->sent)->toHaveCount(1)->and($this->fake->sent[0]['endpoint'])->toBe('https://push.example.com/budi');
});

it('serves a manifest and a service worker that only handles push', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
    expect($manifest)->toMatchArray(['display' => 'standalone', 'start_url' => '/', 'scope' => '/'])
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512');
    foreach ($manifest['icons'] as $icon) {
        expect(file_exists(public_path(ltrim($icon['src'], '/'))))->toBeTrue();
    }
    $worker = file_get_contents(public_path('sw.js'));
    expect($worker)->toContain("addEventListener('push'")->toContain("addEventListener('notificationclick'")->not->toContain('caches.');
});

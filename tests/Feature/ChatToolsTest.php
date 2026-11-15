<?php

use App\Checklists\ChecklistSettings;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\ChronologyReport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GuestRequest;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Enums\Role;
use App\Models\User;

function toolUser(Department $department): User
{
    return User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
}

beforeEach(function () {
    app(ChecklistSettings::class)->put('chat_enabled', '1');
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->foStaff = toolUser($this->fo);
    $this->hkStaff = toolUser($this->hk);
    $this->room = Room::create(['number' => '1206', 'sort' => 1]);
    $this->thread = ChatThread::create(['subject' => 'Room Division', 'created_by' => $this->foStaff->id]);
    foreach ([$this->foStaff, $this->hkStaff] as $user) {
        ChatParticipant::create(['chat_thread_id' => $this->thread->id, 'user_id' => $user->id]);
    }
});

it('offers the forms in the attachment menu with their slash commands, only for enabled features', function () {
    loggedIn($this->foStaff);

    $this->get(route('chat.show', $this->thread))->assertOk()
        ->assertSee('data-chat-tool="'.e(route('permintaan.index', ['obrolan' => $this->thread->id])).'"', false)
        ->assertSee('data-command="p"', false)->assertSee('data-command="k"', false)->assertSee('data-command="c"', false)
        ->assertSee('data-tool-dialog', false);

    app(ChecklistSettings::class)->put('feature_kendala', '0');
    $this->get(route('chat.show', $this->thread))->assertOk()->assertDontSee('data-command="k"', false)->assertSee('data-command="p"', false);
});

it('shows only the request form when opened from a chat', function () {
    loggedIn($this->foStaff);

    $html = $this->get(route('permintaan.index', ['obrolan' => $this->thread->id, 'kamar' => '1206']))->assertOk()
        ->assertSee('name="obrolan" value="'.$this->thread->id.'"', false)->assertSee('value="1206"', false)
        ->assertDontSee(route('permintaan.index', ['status' => 'all']), false)->getContent();
    expect($html)->toMatch('/<html lang="id" class="chat-tool"/')->toMatch('/id="request-dialog"\s+data-autoopen/');
});

it('posts one guest request card per kind into the chat and closes the form', function () {
    loggedIn($this->foStaff);

    $this->post(route('permintaan.store'), ['room' => '1206', 'kinds' => ['mineral_water', 'towel'], 'qty' => ['mineral_water' => 2], 'priority' => 'biasa', 'obrolan' => $this->thread->id])
        ->assertRedirect(route('chat.tool-done', $this->thread));

    $cards = ChatMessage::where('chat_thread_id', $this->thread->id)->whereNotNull('guest_request_id')->get();
    expect($cards)->toHaveCount(2)->and($cards->first()->user_id)->toBe($this->foStaff->id)
        ->and($cards->pluck('guest_request_id')->sort()->values()->all())->toBe(GuestRequest::orderBy('id')->pluck('id')->all());
    $this->get(route('chat.tool-done', $this->thread))->assertOk()->assertSee('chat-tool-done');

    // Housekeeping melihat kartunya dengan tombol Saya ambil / Selesai, dan bisa menekannya langsung dari obrolan.
    $this->post(route('logout'));
    app('auth')->forgetGuards();
    loggedIn($this->hkStaff);
    $item = GuestRequest::where('kind', 'towel')->firstOrFail();
    $this->get(route('chat.show', $this->thread))->assertOk()->assertSee('Kamar 1206 · 2× Air mineral')->assertSee(route('permintaan.claim', $item), false);
    $this->from(route('chat.show', $this->thread))->post(route('permintaan.complete', $item))->assertRedirect(route('chat.show', $this->thread));
    expect($item->refresh()->status)->toBe('selesai');
});

it('posts issue and chronology cards when created from the chat', function () {
    loggedIn($this->foStaff);

    $this->post(route('kamar.issues.store'), ['room_id' => $this->room->id, 'source' => 'tamu', 'priority' => 'biasa', 'description' => 'Air kamar mandi ngeblock', 'obrolan' => $this->thread->id])
        ->assertRedirect(route('chat.tool-done', $this->thread));
    expect(ChatMessage::where('room_issue_id', RoomIssue::firstOrFail()->id)->where('chat_thread_id', $this->thread->id)->exists())->toBeTrue();

    $this->post(route('chronology.store'), ['check_in' => now()->toDateString(), 'guests' => [['name' => 'Leanne', 'room_number' => '1206']], 'entries' => [['body' => 'Guest reported hair in bathroom.']], 'intent' => 'draft', 'obrolan' => $this->thread->id])
        ->assertRedirect(route('chat.tool-done', $this->thread));
    expect(ChatMessage::where('chronology_report_id', ChronologyReport::firstOrFail()->id)->where('chat_thread_id', $this->thread->id)->exists())->toBeTrue();
});

it('ignores a chat the user cannot post to, and saves normally', function () {
    $outsider = toolUser($this->fo);
    loggedIn($outsider);

    $this->post(route('permintaan.store'), ['room' => '1206', 'kinds' => ['towel'], 'priority' => 'biasa', 'obrolan' => $this->thread->id])
        ->assertRedirect(route('permintaan.index'));
    expect(GuestRequest::count())->toBe(1)->and(ChatMessage::whereNotNull('guest_request_id')->exists())->toBeFalse();
});

it('previews a free-typed request and records it when sent with catat', function () {
    loggedIn($this->foStaff);

    $this->postJson(route('chat.quick', $this->thread), ['text' => '1206 req 2 mineral water + 1 bathtowel *eka'])->assertOk()
        ->assertJson(['type' => 'request', 'ok' => true, 'room' => '1206', 'items' => ['2× Air mineral', '1× Handuk'], 'urgent' => false]);

    $response = $this->postJson(route('chat.send', $this->thread), ['body' => '1206 req 2 mineral water + 1 bathtowel *eka', 'catat' => '1'])->assertOk();
    expect($response->json('messages'))->toHaveCount(2);

    $requests = GuestRequest::orderBy('id')->get();
    expect($requests)->toHaveCount(2)->and($requests->pluck('kind')->all())->toBe(['mineral_water', 'towel'])
        ->and($requests->first()->quantity)->toBe(2)->and($requests->first()->room_id)->toBe($this->room->id)
        ->and($requests->first()->assigned_department_id)->toBe($this->hk->id)->and($requests->first()->requested_by)->toBe($this->foStaff->id)
        ->and($requests->first()->source)->toBe('chat')->and($requests->first()->note)->toBe('1206 req 2 mineral water + 1 bathtowel *eka');
    $text = ChatMessage::where('body', '1206 req 2 mineral water + 1 bathtowel *eka')->firstOrFail();
    expect($text->guest_request_id)->toBe($requests->first()->id)
        ->and(ChatMessage::where('guest_request_id', $requests->last()->id)->whereNull('body')->exists())->toBeTrue();
});

it('sends free text without recording when not confirmed or the room is unknown', function () {
    loggedIn($this->foStaff);

    $this->postJson(route('chat.send', $this->thread), ['body' => '1206 req 2 mineral water'])->assertOk();
    $this->postJson(route('chat.quick', $this->thread), ['text' => '9999 req 2 mineral water'])->assertOk()
        ->assertJson(['type' => 'request', 'ok' => false, 'warnings' => ['Kamar 9999 tidak ada di data kamar. Periksa lagi nomornya.']]);
    $this->postJson(route('chat.send', $this->thread), ['body' => '9999 req 2 mineral water', 'catat' => '1'])->assertOk();

    expect(GuestRequest::count())->toBe(0)->and(ChatMessage::where('chat_thread_id', $this->thread->id)->count())->toBe(2);
});

it('previews an issue with a link to the prefilled issue form', function () {
    loggedIn($this->foStaff);

    $json = $this->postJson(route('chat.quick', $this->thread), ['text' => '1206 air kamar mandi ngeblock, tamu di kamar'])->assertOk()
        ->assertJson(['type' => 'issue', 'ok' => true, 'room' => '1206', 'category' => 'Air & sanitasi', 'urgent' => true])->json();

    $this->get($json['url'])->assertOk()->assertSee('name="room_number" value="1206"', false)
        ->assertSee('1206 air kamar mandi ngeblock, tamu di kamar</textarea>', false)->assertSee('value="mendesak" checked', false);
    $this->postJson(route('chat.quick', $this->thread), ['text' => '1206 CO *anggun'])->assertOk()->assertJson(['type' => null]);
});

it('lets the Administrator switch free typing off, per part, and pick the default department for Lainnya', function () {
    $it = Department::factory()->create(['name' => 'IT']);
    $admin = User::factory()->role(Role::Admin)->create(['employee_id' => Employee::factory()->for($this->fo)->create()->id]);
    loggedIn($admin);
    $this->get(route('admin.settings.edit'))->assertOk()->assertSee('Ketik bebas di obrolan')->assertSee('Tujuan bawaan permintaan');

    $this->put(route('admin.settings.quick.update'), ['quick' => ['quick_entry' => '1', 'quick_requests' => '1'], 'quick_other_department_id' => $it->id])->assertRedirect();
    $settings = app(ChecklistSettings::class);
    expect($settings->quickEntry('quick_entry'))->toBeTrue()->and($settings->quickEntry('quick_requests'))->toBeTrue()
        ->and($settings->quickEntry('quick_issues'))->toBeFalse()->and($settings->quickOtherDepartmentId())->toBe($it->id);

    // Lainnya dari ketik bebas langsung ke departemen pilihan; kendala tidak lagi disarankan.
    ChatParticipant::create(['chat_thread_id' => $this->thread->id, 'user_id' => $admin->id]);
    $this->postJson(route('chat.quick', $this->thread), ['text' => '1206 req lan cable'])->assertJson(['type' => 'request', 'items' => ['Lainnya: lan cable'], 'department_id' => $it->id]);
    $this->postJson(route('chat.quick', $this->thread), ['text' => '1206 AC tidak dingin'])->assertJson(['type' => null]);
    $this->postJson(route('chat.send', $this->thread), ['body' => '1206 req lan cable', 'catat' => '1'])->assertOk();
    expect(GuestRequest::firstOrFail()->assigned_department_id)->toBe($it->id);

    // Dimatikan seluruhnya: tidak ada pratinjau dan pesan tidak dicatat walau dikirim dengan catat=1.
    $this->put(route('admin.settings.quick.update'), ['quick' => ['quick_requests' => '1', 'quick_issues' => '1']])->assertRedirect();
    $this->get(route('chat.show', $this->thread))->assertOk()->assertSee('data-quick-url=""', false);
    $this->postJson(route('chat.quick', $this->thread), ['text' => '1206 req 2 mineral water'])->assertJson(['type' => null]);
    $this->postJson(route('chat.send', $this->thread), ['body' => '1206 req 2 mineral water', 'catat' => '1'])->assertOk();
    expect(GuestRequest::count())->toBe(1);

    $this->post(route('logout'));
    app('auth')->forgetGuards();
    loggedIn($this->foStaff);
    $this->put(route('admin.settings.quick.update'), ['quick' => []])->assertForbidden();
});

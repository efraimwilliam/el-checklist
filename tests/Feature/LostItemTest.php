<?php

use App\Chat\QuickEntry;
use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LostItem;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    app(ChecklistSettings::class)->put('chat_enabled', '1');
    $hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->hk = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($hk)->create()->id]);
    $this->fo = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for(Department::factory()->create(['name' => 'Front Office']))->create()->id]);
    $this->room = Room::create(['number' => '1203', 'sort' => 1]);
    $this->thread = ChatThread::create(['subject' => 'Room Division', 'created_by' => $this->hk->id]);
    foreach ([$this->hk, $this->fo] as $user) {
        ChatParticipant::create(['chat_thread_id' => $this->thread->id, 'user_id' => $user->id]);
    }
});

it('reads "tertinggal" messages as lost items before requests and issues', function () {
    $read = app(QuickEntry::class)->read('1203 complete tertinggal dompet hitam *edy');
    expect($read['type'])->toBe('lost')->and($read['room']?->number)->toBe('1203')->and($read['description'])->toBe('dompet hitam');

    expect(app(QuickEntry::class)->read('Komplit 1203, tertinggal')['description'])->toBeNull()
        ->and(app(QuickEntry::class)->read('1203 req 2 towel')['type'])->toBe('request');
});

it('records a captioned chat photo as a lost item and copies the photo', function () {
    loggedIn($this->hk);

    $this->postJson(route('chat.quick', $this->thread), ['text' => '1203 complete tertinggal charger'])->assertOk()
        ->assertJson(['type' => 'lost', 'ok' => true, 'room' => '1203', 'description' => 'charger']);

    $photo = UploadedFile::fake()->image('barang.jpg', 400, 300);
    $this->post(route('chat.send', $this->thread), ['body' => '1203 complete tertinggal charger', 'photo' => $photo, 'catat' => '1'], ['Accept' => 'application/json'])->assertOk();

    $item = LostItem::firstOrFail();
    expect($item->room_id)->toBe($this->room->id)->and($item->description)->toBe('charger')->and($item->source)->toBe('chat')
        ->and($item->found_by)->toBe($this->hk->id)->and($item->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($item->photo_path);
    $message = ChatMessage::where('lost_item_id', $item->id)->firstOrFail();
    expect($message->photo_path)->not->toBe($item->photo_path); // salinan sendiri, tidak ikut terhapus bila pesan dihapus

    // Tanpa catat=1 (pratinjau ditutup) tidak dicatat.
    $this->postJson(route('chat.send', $this->thread), ['body' => '1203 tertinggal kacamata'])->assertOk();
    expect(LostItem::count())->toBe(1);
});

it('logs from the chat form and updates the status with a new card as a trail', function () {
    loggedIn($this->hk);

    $this->get(route('lost.index', ['obrolan' => $this->thread->id, 'catat' => 1]))->assertOk()->assertSee('name="obrolan" value="'.$this->thread->id.'"', false);
    $this->post(route('lost.store'), ['room' => '1203', 'description' => 'Paspor hijau', 'stored_at' => 'Kantor HK', 'obrolan' => $this->thread->id])
        ->assertRedirect(route('chat.tool-done', $this->thread));
    $item = LostItem::firstOrFail();
    expect($item->status)->toBe('disimpan')->and($item->stored_at)->toBe('Kantor HK')
        ->and(ChatMessage::where('lost_item_id', $item->id)->count())->toBe(1);

    $this->post(route('logout'));
    app('auth')->forgetGuards();
    loggedIn($this->fo);
    $this->get(route('chat.show', $this->thread))->assertOk()->assertSee('Paspor hijau')->assertSee('data-open-tool', false)->assertSee('data-command="t"', false);

    $this->post(route('lost.status', $item), ['status' => 'diambil', 'obrolan' => $this->thread->id])->assertSessionHasErrors('resolution_note');
    $this->post(route('lost.status', $item), ['status' => 'diambil', 'resolution_note' => 'Mr. Smith di FO', 'obrolan' => $this->thread->id])
        ->assertRedirect(route('chat.tool-done', $this->thread));
    expect($item->refresh()->status)->toBe('diambil')->and($item->resolved_by)->toBe($this->fo->id)
        ->and(ChatMessage::where('lost_item_id', $item->id)->count())->toBe(2)
        ->and(ChatMessage::where('lost_item_id', $item->id)->latest('id')->value('body'))->toContain('Diambil tamu');
});

it('is part of the chat: no menu entry, and closed when chat is off', function () {
    loggedIn($this->hk);
    $this->get(route('checklists.index'))->assertOk()->assertDontSee(route('lost.index'), false);

    app(ChecklistSettings::class)->put('chat_enabled', '0');
    $this->get(route('lost.index'))->assertNotFound();
});

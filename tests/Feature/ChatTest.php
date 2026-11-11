<?php

use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IssueCategory;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function chatUser(Department $department, Role $role = Role::Staff): User
{
    return User::factory()->role($role)->create(['employee_id' => Employee::factory()->for($department)->create()->id]);
}

beforeEach(function () {
    $this->dept = Department::factory()->create(['name' => 'Engineering']);
    $this->other = Department::factory()->create(['name' => 'Front Office']);
    $this->ani = chatUser($this->dept);
    $this->budi = chatUser($this->dept);
    $this->citra = chatUser($this->other);
    $this->hr = chatUser($this->other, Role::Hr);
    $this->admin = chatUser($this->other, Role::Admin);
    app(ChecklistSettings::class)->put('chat_enabled', '1');
});

/** Ganti pengguna di tengah tes: keluar dulu, karena halaman login hanya untuk tamu. */
function chatAs(User $user)
{
    test()->post(route('logout'));
    app('auth')->forgetGuards();

    return loggedIn($user);
}

function directChat($test, User $from, User $to): ChatThread
{
    chatAs($from);
    $test->post(route('chat.direct', $to))->assertRedirect();

    return ChatThread::where('direct_key', ChatThread::directKey($from->id, $to->id))->firstOrFail();
}

function groupChat($test, array $members): ChatThread
{
    chatAs($test->ani);
    $test->post(route('chat.store'), ['subject' => 'Jadwal AC lantai 3', 'users' => collect($members)->pluck('id')->all()])->assertRedirect();

    return ChatThread::whereNotNull('subject')->firstOrFail();
}

function say($test, ChatThread $thread, string $text, array $extra = [])
{
    return $test->postJson(route('chat.send', $thread), ['body' => $text] + $extra);
}

it('is off by default and blocks everything when disabled', function () {
    app(ChecklistSettings::class)->put('chat_enabled', '0');
    chatAs($this->ani);

    $this->get(route('chat.index'))->assertForbidden();
    $this->post(route('chat.direct', $this->budi))->assertForbidden();
    $this->get('/dashboard')->assertDontSee(route('chat.index'), false);
    expect($this->ani->unreadChatCount())->toBe(0);
});

it('opens one private chat per pair, from either side, and only shows it once it has messages', function () {
    $thread = directChat($this, $this->ani, $this->budi);
    expect($thread->participants()->count())->toBe(2);

    $this->get(route('chat.index'))->assertOk()->assertDontSee(route('chat.show', $thread), false);
    $this->get(route('chat.show', $thread))->assertOk()->assertSee($this->budi->name());
    say($this, $thread, 'Halo Budi')->assertOk()->assertJsonPath('messages.0.html', fn ($html) => str_contains($html, 'Halo Budi'));
    $this->get(route('chat.index'))->assertSee(route('chat.show', $thread), false)->assertSee('Halo Budi');

    expect(directChat($this, $this->budi, $this->ani)->id)->toBe($thread->id)->and(ChatThread::count())->toBe(1);
    $this->get(route('chat.show', $thread))->assertOk()->assertSee($this->ani->name())->assertSee('1 pesan belum dibaca');

    chatAs($this->ani);
    $this->post(route('chat.direct', $this->ani))->assertNotFound();
});

it('keeps private chats private from colleagues but readable by HR and Administrator', function () {
    $thread = directChat($this, $this->ani, $this->budi);
    say($this, $thread, 'rahasia kerja')->assertOk();

    chatAs($this->citra);
    $this->get(route('chat.show', $thread))->assertForbidden();
    $this->getJson(route('chat.poll', $thread))->assertForbidden();
    say($this, $thread, 'nyelonong')->assertForbidden();

    foreach ([$this->hr, $this->admin] as $reader) {
        chatAs($reader);
        $this->get(route('chat.show', $thread))->assertOk()->assertSee('rahasia kerja')->assertSee('memantau');
        say($this, $thread, 'saya ikut')->assertForbidden();
        $this->get(route('chat.index', ['semua' => 1]))->assertOk()->assertSee('rahasia kerja');
    }

    chatAs($this->citra);
    $this->get(route('chat.index', ['semua' => 1]))->assertOk()->assertDontSee('rahasia kerja');
});

it('creates groups with a system note, lets members add people and leave', function () {
    $thread = groupChat($this, [$this->budi]);
    expect($thread->participants()->count())->toBe(2)
        ->and($thread->messages()->first())->type->toBe('system')->body->toContain('membuat grup');

    $gone = chatUser($this->dept);
    $gone->update(['is_active' => false]);
    $this->post(route('chat.invite', $thread), ['users' => [$this->citra->id, $gone->id]])->assertRedirect();
    expect($thread->participants()->pluck('user_id')->all())->toContain($this->citra->id)->not->toContain($gone->id)
        ->and($thread->messages()->latest('id')->first()->body)->toContain('menambahkan');

    chatAs($this->citra);
    $this->get(route('chat.show', $thread))->assertOk()->assertSee('Keluar dari grup');
    $this->post(route('chat.leave', $thread))->assertRedirect(route('chat.index'));
    $this->get(route('chat.show', $thread))->assertForbidden();
    expect($thread->messages()->latest('id')->first()->body)->toContain('keluar');

    // Chat pribadi tidak punya tambah anggota / keluar.
    $direct = directChat($this, $this->ani, $this->budi);
    $this->post(route('chat.invite', $direct), ['users' => [$this->citra->id]])->assertForbidden();
    $this->post(route('chat.leave', $direct))->assertForbidden();
});

it('validates groups and messages', function () {
    chatAs($this->ani);
    $this->post(route('chat.store'), ['subject' => '', 'users' => []])->assertSessionHasErrors(['subject', 'users']);
    $this->post(route('chat.store'), ['subject' => 'Sendiri', 'users' => [$this->ani->id]])->assertSessionHasErrors('users');
    expect(ChatThread::count())->toBe(0);

    $thread = directChat($this, $this->ani, $this->budi);
    say($this, $thread, '')->assertStatus(422);
    say($this, $thread, str_repeat('a', 4001))->assertStatus(422);
});

it('replies to a message, sends photos privately, and deletes for everyone', function () {
    Storage::fake('local');
    $thread = directChat($this, $this->ani, $this->budi);
    $first = say($this, $thread, 'AC kamar 305 bocor')->json('messages.0.id');

    chatAs($this->budi);
    $reply = say($this, $thread, 'Saya ke sana', ['reply_to' => $first])->assertOk();
    expect($reply->json('messages.0.html'))->toContain('AC kamar 305 bocor')->toContain('data-jump="'.$first.'"');

    $this->post(route('chat.send', $thread), ['body' => '', 'photo' => UploadedFile::fake()->image('ac.jpg', 800, 600)], ['Accept' => 'application/json'])->assertOk();
    $photo = ChatMessage::whereNotNull('photo_path')->firstOrFail();
    Storage::disk('local')->assertExists($photo->photo_path);
    $this->get(route('chat.photo', [$thread, $photo]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->post(route('chat.send', $thread), ['photo' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

    chatAs($this->citra);
    $this->get(route('chat.photo', [$thread, $photo]))->assertForbidden();

    // Hanya pengirim yang boleh menghapus; gelembungnya tetap, isinya hilang, fotonya ikut dihapus.
    chatAs($this->ani);
    $this->deleteJson(route('chat.destroy', [$thread, $photo]))->assertForbidden();
    chatAs($this->budi);
    $this->deleteJson(route('chat.destroy', [$thread, $photo]))->assertOk()->assertJsonPath('changed.0.html', fn ($html) => str_contains($html, 'Anda menghapus pesan ini'));
    expect($photo->fresh())->body->toBeNull()->photo_path->toBeNull()->deleted_at->not->toBeNull();
    Storage::disk('local')->assertMissing($photo->getOriginal('photo_path'));

    chatAs($this->ani);
    $this->getJson(route('chat.poll', ['thread' => $thread, 'after' => $photo->id, 'since' => now()->subMinute()->toIso8601String()]))
        ->assertOk()->assertJsonPath('changed.0.html', fn ($html) => str_contains($html, 'Pesan ini dihapus'));
});

it('marks messages read (blue ticks), counts unread and shows who is typing', function () {
    $thread = directChat($this, $this->ani, $this->budi);
    $id = say($this, $thread, 'Cek panel listrik')->json('messages.0.id');
    expect($this->getJson(route('chat.poll', ['thread' => $thread, 'after' => $id]))->json('read_upto'))->toBeLessThan($id)
        ->and($this->budi->unreadChatCount())->toBe(1);

    chatAs($this->budi);
    $this->get(route('chat.show', $thread))->assertOk();
    expect(User::find($this->budi->id)->unreadChatCount())->toBe(0);
    $this->postJson(route('chat.typing', $thread))->assertOk();

    chatAs($this->ani);
    $poll = $this->getJson(route('chat.poll', ['thread' => $thread, 'after' => $id]))->assertOk();
    expect($poll->json('read_upto'))->toBe($id)->and($poll->json('typing'))->toBe([$this->budi->name()]);
});

it('opens one shared thread per room issue for people who may see that issue', function () {
    $room = Room::create(['number' => '305', 'floor' => '3', 'type' => 'Deluxe', 'sort' => 1]);
    $category = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->dept->id, 'sort' => 99]);
    $issue = RoomIssue::create([
        'room_id' => $room->id, 'category_id' => $category->id, 'source' => 'tamu', 'description' => 'AC bocor', 'priority' => 'biasa',
        'reported_by' => $this->citra->id, 'assigned_department_id' => $this->dept->id, 'status' => 'baru',
    ]);

    chatAs($this->ani);
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee(route('chat.issue', $issue), false);
    $this->get(route('chat.issue', $issue))->assertRedirect();
    $thread = ChatThread::firstOrFail();
    say($this, $thread, 'Saya cek sore ini')->assertOk();

    chatAs($this->citra);
    $this->get(route('chat.issue', $issue))->assertRedirect(route('chat.show', $thread));
    $this->get(route('chat.show', $thread))->assertOk()->assertSee('Saya cek sore ini')->assertSee('Kamar 305');
    expect(ChatThread::count())->toBe(1);

    chatAs(chatUser(Department::factory()->create(['name' => 'Laundry'])));
    $this->get(route('chat.issue', $issue))->assertForbidden();
    $this->get(route('chat.show', $thread))->assertForbidden();
});

it('lets the Administrator switch chat on and off from settings', function () {
    app(ChecklistSettings::class)->put('chat_enabled', '0');
    chatAs($this->admin);

    $this->get(route('admin.settings.edit'))->assertOk()->assertSee('Aktifkan obrolan pekerjaan');
    $this->put(route('admin.settings.rules.update'), ['chat_enabled' => '1'])->assertRedirect();
    expect(app(ChecklistSettings::class)->chatEnabled())->toBeTrue();
    $this->get(route('chat.index'))->assertOk();

    $this->put(route('admin.settings.rules.update'), [])->assertRedirect();
    expect(app(ChecklistSettings::class)->chatEnabled())->toBeFalse();
    $this->get(route('chat.index'))->assertForbidden();

    chatAs($this->hr);
    $this->put(route('admin.settings.rules.update'), ['chat_enabled' => '1'])->assertForbidden();
});

it('records @mentions of participants, highlights them, and flags the thread for the person mentioned', function () {
    $made = chatUser($this->dept);
    $made->employee->update(['name' => 'Made Demo']);
    $madePutra = chatUser($this->dept);
    $madePutra->employee->update(['name' => 'Made Demo Putra']);
    $thread = groupChat($this, [$this->budi, $made, $madePutra]);

    $html = say($this, $thread, 'Tolong @made demo putra cek lantai 3, @Made Demo bantu. @Citra bukan anggota')->assertOk()->json('messages.0.html');
    $message = $thread->messages()->latest('id')->first();
    expect($message->mentions)->toEqualCanonicalizing([$madePutra->id, $made->id])
        ->and($html)->toContain('data-mention="'.$madePutra->id.'"')->toContain('data-mention="'.$made->id.'"');

    // Isi tetap di-escape walau ada sorotan.
    say($this, $thread, '<b>@Made Demo</b>')->assertOk()->assertJsonPath('messages.0.html', fn ($h) => str_contains($h, '&lt;b&gt;') && ! str_contains($h, '<b>'));

    chatAs($made);
    $this->get(route('chat.index'))->assertOk()->assertSee('Anda disebut');
    $this->get(route('chat.show', $thread))->assertOk()->assertSee('rounded bg-sky-100', false);
    $this->get(route('chat.index'))->assertOk()->assertDontSee('Anda disebut');

    chatAs($this->budi);
    $this->get(route('chat.index'))->assertOk()->assertDontSee('Anda disebut');
});

it('sends documents privately, shows links as clickable, and lists media, links and documents', function () {
    Storage::fake('local');
    $thread = directChat($this, $this->ani, $this->budi);

    $this->post(route('chat.send', $thread), ['body' => 'Laporan bulan ini', 'file' => UploadedFile::fake()->create('Laporan AC.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('messages.0.html', fn ($html) => str_contains($html, 'Laporan AC.pdf') && str_contains($html, 'PDF'));
    $doc = ChatMessage::whereNotNull('file_path')->firstOrFail();
    expect($doc->file_mime)->toBe('application/pdf')->and($doc->file_path)->not->toContain('Laporan');
    $this->get(route('chat.file', [$thread, $doc]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->post(route('chat.send', $thread), ['file' => UploadedFile::fake()->create('jahat.php', 5, 'application/x-php')], ['Accept' => 'application/json'])->assertStatus(422);
    $this->post(route('chat.send', $thread), ['file' => UploadedFile::fake()->create('besar.pdf', 9000, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

    say($this, $thread, 'Cek https://example.com/manual?a=1&b=2. <script>x</script>')->assertOk()
        ->assertJsonPath('messages.0.html', fn ($html) => str_contains($html, 'href="https://example.com/manual?a=1&amp;b=2"') && str_contains($html, 'rel="noopener noreferrer nofollow"') && ! str_contains($html, '<script>'));

    $media = $this->getJson(route('chat.media', $thread))->assertOk()->json('html');
    expect($media)->toContain('Laporan AC.pdf')->toContain('https://example.com/manual?a=1&amp;b=2')->toContain('Dokumen (1)')->toContain('Tautan (1)');

    chatAs($this->citra);
    $this->get(route('chat.file', [$thread, $doc]))->assertForbidden();
    $this->getJson(route('chat.media', $thread))->assertForbidden();

    // Hapus untuk semua ikut menghapus berkasnya.
    chatAs($this->ani);
    $this->deleteJson(route('chat.destroy', [$thread, $doc]))->assertOk();
    Storage::disk('local')->assertMissing($doc->file_path);
    expect($doc->fresh()->file_name)->toBeNull();
});

it('searches text inside a conversation and opens around an older message', function () {
    $thread = directChat($this, $this->ani, $this->budi);
    $old = say($this, $thread, 'Kunci gudang ada di laci <meja> FO')->json('messages.0.id');
    say($this, $thread, 'pesan lain')->assertOk();

    $results = $this->getJson(route('chat.search', ['thread' => $thread, 'q' => 'laci']))->assertOk()->json('results');
    expect($results)->toHaveCount(1)
        ->and($results[0]['id'])->toBe($old)
        ->and($results[0]['html'])->toContain('<mark')->toContain('&lt;meja&gt;')->not->toContain('<meja>');
    $this->getJson(route('chat.search', ['thread' => $thread, 'q' => 'a']))->assertOk()->assertJsonCount(0, 'results');

    $this->get(route('chat.show', ['thread' => $thread, 'pesan' => $old]))->assertOk()->assertSee('data-target="'.$old.'"', false);

    chatAs($this->citra);
    $this->getJson(route('chat.search', ['thread' => $thread, 'q' => 'laci']))->assertForbidden();
});

it('lets group members change and remove the group photo, but not in private chats', function () {
    Storage::fake('local');
    $thread = groupChat($this, [$this->budi]);

    $this->post(route('chat.group-photo.update', $thread), ['photo' => UploadedFile::fake()->image('tim.jpg', 400, 400)])->assertRedirect(route('chat.show', $thread));
    $path = $thread->fresh()->photo_path;
    expect($path)->toStartWith('chat-group-photos/');
    Storage::disk('local')->assertExists($path);
    expect($thread->messages()->latest('id')->first()->body)->toContain('mengganti foto grup');
    $this->get(route('chat.group-photo', $thread))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('chat.index'))->assertSee(route('chat.group-photo', $thread), false);

    $this->post(route('chat.group-photo.update', $thread), ['hapus' => 1])->assertRedirect();
    Storage::disk('local')->assertMissing($path);
    expect($thread->fresh()->photo_path)->toBeNull();

    chatAs($this->citra);
    $this->post(route('chat.group-photo.update', $thread), ['photo' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();

    $direct = directChat($this, $this->ani, $this->budi);
    $this->post(route('chat.group-photo.update', $direct), ['photo' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
});

it('sends a room issue to chats and contacts as a card', function () {
    $room = Room::create(['number' => '305', 'floor' => '3', 'type' => 'Deluxe', 'sort' => 1]);
    $category = IssueCategory::create(['name' => 'AC uji', 'department_id' => $this->dept->id, 'sort' => 99]);
    $issue = RoomIssue::create([
        'room_id' => $room->id, 'category_id' => $category->id, 'source' => 'tamu', 'description' => 'AC bocor menetes ke kasur', 'priority' => 'mendesak',
        'reported_by' => $this->citra->id, 'assigned_department_id' => $this->dept->id, 'status' => 'baru',
    ]);
    $group = groupChat($this, [$this->budi]);

    // Ani (Engineering) boleh melihat kendala itu: kirim ke grup + chat pribadi dengan HR sekaligus.
    $this->get(route('kamar.issues.show', $issue))->assertOk()->assertSee('Kirim ke obrolan');
    $this->post(route('chat.share-issue', $issue), ['threads' => [$group->id], 'people' => [$this->hr->id], 'body' => 'Tolong @'.$this->budi->name().' bantu'])
        ->assertRedirect(route('kamar.issues.show', $issue))->assertSessionHas('status');

    $shared = ChatMessage::whereNotNull('room_issue_id')->get();
    expect($shared)->toHaveCount(2)
        ->and($shared->pluck('room_issue_id')->unique()->all())->toBe([$issue->id])
        ->and($shared->firstWhere('chat_thread_id', $group->id)->mentions)->toBe([$this->budi->id]);
    $direct = ChatThread::where('direct_key', ChatThread::directKey($this->ani->id, $this->hr->id))->firstOrFail();

    // Satu tujuan = langsung ke obrolannya.
    $this->post(route('chat.share-issue', $issue), ['threads' => [$direct->id]])->assertRedirect(route('chat.show', $direct));

    // Kartu tampil di obrolan; yang tidak berhak membuka kendala tetap melihat ringkasannya tanpa tautan.
    chatAs($this->budi);
    $this->get(route('chat.show', $group))->assertOk()->assertSee('Kamar 305')->assertSee('AC bocor menetes ke kasur')->assertSee(route('kamar.issues.show', $issue), false);
    $this->get(route('chat.index'))->assertSee('🛠');

    $outsider = chatUser(Department::factory()->create(['name' => 'Kitchen']));
    chatAs($this->ani);
    $this->post(route('chat.invite', $group), ['users' => [$outsider->id]]);
    chatAs($outsider);
    $this->get(route('chat.show', $group))->assertOk()->assertSee('Kamar 305')->assertSee('tidak punya akses')->assertDontSee(route('kamar.issues.show', $issue), false);

    // Tanpa hak melihat kendala, tidak bisa mengirimnya; tanpa tujuan ditolak; obrolan orang lain dilewati.
    $this->post(route('chat.share-issue', $issue), ['people' => [$this->ani->id]])->assertForbidden();
    chatAs($this->ani);
    $this->post(route('chat.share-issue', $issue), [])->assertSessionHasErrors('share');
    $other = directChat($this, $this->budi, $this->citra);
    chatAs($this->ani);
    $this->post(route('chat.share-issue', $issue), ['threads' => [$other->id]])->assertSessionHasErrors('share');
});

it('sends a checklist to chat as a reminder for its owner', function () {
    $position = \App\Models\Position::factory()->for($this->dept)->create(['name' => 'Teknisi']);
    \App\Models\Task::factory()->count(3)->for($position)->create();
    $this->ani->employee->update(['checklist_position_id' => $position->id]);
    $checklist = makeChecklist($this->ani->employee, $position, null, null);
    $checklist->items()->first()->update(['status' => 'done']);
    $hod = User::factory()->hodOf($this->dept)->create(['employee_id' => Employee::factory()->for($this->dept)->create()->id]);

    chatAs($hod);
    // Pemilik checklist sudah dicentang di dialog.
    $this->get(route('checklists.show', $checklist))->assertOk()->assertSee('Kirim ke obrolan')
        ->assertSee('value="'.$this->ani->id.'" class="h-5 w-5 rounded-full accent-[#b08a3e]" data-share-pick checked', false);
    $this->post(route('chat.share-checklist', $checklist), ['people' => [$this->ani->id], 'body' => 'Tolong diselesaikan sebelum jam 3'])->assertRedirect();
    $thread = ChatThread::where('direct_key', ChatThread::directKey($hod->id, $this->ani->id))->firstOrFail();
    expect($thread->messages()->latest('id')->first()->checklist_id)->toBe($checklist->id);

    chatAs($this->ani);
    $this->get(route('chat.show', $thread))->assertOk()
        ->assertSee('1/3 diisi')->assertSee('Belum lengkap')->assertSee('Pengingat: checklist Anda belum lengkap.')
        ->assertSee(route('checklists.fill', $checklist), false);
    $this->get(route('chat.index'))->assertSee('📋');

    // Yang tidak boleh melihat checklist tidak bisa mengirimnya.
    chatAs($this->citra);
    $this->post(route('chat.share-checklist', $checklist), ['people' => [$this->ani->id]])->assertForbidden();
});

it('sends a chronology report to chat as a card', function () {
    chatAs($this->citra); // penulis (Front Office)
    $this->post(route('chronology.store'), [
        'nationality' => 'Australia', 'booking_source' => 'Alaric', 'check_in' => '2026-11-02', 'check_out' => '2026-11-05', 'intent' => 'draft',
        'guests' => [['name' => 'Giuseppe Mazzarella', 'room_number' => '1114'], ['name' => 'Ida Gervasi', 'room_number' => '1116']],
        'entries' => [['body' => 'The guest asked the Front Office team regarding the room sign.', 'occurred_at' => '']],
    ])->assertRedirect();
    $report = \App\Models\ChronologyReport::firstOrFail();
    $this->get(route('chronology.show', $report))->assertOk()->assertSee('Kirim ke obrolan');

    $this->post(route('chat.share-chronology', $report), ['people' => [$this->hr->id, $this->ani->id], 'body' => 'Mohon dibaca'])
        ->assertRedirect(route('chronology.show', $report))->assertSessionHas('status');
    expect(ChatMessage::where('chronology_report_id', $report->id)->count())->toBe(2);
    $toHr = ChatThread::where('direct_key', ChatThread::directKey($this->citra->id, $this->hr->id))->firstOrFail();

    // HR boleh membuka laporannya; Ani (Engineering) hanya melihat ringkasan kartu.
    chatAs($this->hr);
    $this->get(route('chat.show', $toHr))->assertOk()->assertSee('Chronology Report')->assertSee('Giuseppe Mazzarella')
        ->assertSee('Room 1114, 1116')->assertSee('Draft')->assertSee(route('chronology.show', $report), false);
    chatAs($this->ani);
    $toAni = ChatThread::where('direct_key', ChatThread::directKey($this->citra->id, $this->ani->id))->firstOrFail();
    $this->get(route('chat.show', $toAni))->assertOk()->assertSee('Giuseppe Mazzarella')->assertSee('tidak punya akses ke laporan')
        ->assertDontSee(route('chronology.show', $report), false);
    $this->get(route('chat.index'))->assertSee('📝');

    // Yang tidak boleh melihat laporan tidak bisa mengirimnya.
    $this->post(route('chat.share-chronology', $report), ['people' => [$this->budi->id]])->assertForbidden();
});

it('shows a floating chat button with the unread count on other pages, not on chat pages or when chat is off', function () {
    $thread = directChat($this, $this->ani, $this->budi);
    say($this, $thread, 'Halo')->assertOk();

    chatAs($this->budi);
    $this->get(route('checklists.index'))->assertOk()->assertSee('data-chat-widget', false)->assertSee('Obrolan, 1 pesan baru', false);
    $this->getJson(route('chat.unread'))->assertOk()->assertExactJson(['unread' => 1]);
    $this->get(route('chat.index'))->assertOk()->assertDontSee('data-chat-widget', false);

    app(ChecklistSettings::class)->put('chat_enabled', '0');
    $this->get(route('checklists.index'))->assertOk()->assertDontSee('data-chat-widget', false);
    $this->getJson(route('chat.unread'))->assertForbidden();
});

it('lets each user pin chats, keeps pinned ones on top in their own order, and limits the number of pins', function () {
    $other = [$this->budi, $this->citra, $this->hr, $this->admin, chatUser($this->dept), chatUser($this->dept)];
    $threads = [];
    foreach ($other as $person) {
        $threads[] = directChat($this, $this->ani, $person);
        say($this, end($threads), 'halo '.$person->id)->assertOk();
    }
    [$t1, $t2, $t3, $t4, $t5, $t6] = $threads;

    // Sematkan t3, lalu t1, lalu t2: urutan mengikuti kapan disematkan.
    foreach ([$t3, $t1, $t2] as $thread) {
        $this->postJson(route('chat.pin', $thread))->assertOk()->assertJson(['pinned' => true]);
    }
    $order = fn () => ChatParticipant::where('user_id', $this->ani->id)->whereNotNull('pin_order')->orderBy('pin_order')->pluck('chat_thread_id')->all();
    expect($order())->toBe([$t3->id, $t1->id, $t2->id]);

    $html = $this->get(route('chat.index'))->assertOk()->assertSee('Disematkan')->getContent();
    $positions = collect([$t3, $t1, $t2, $t6])->map(fn ($t) => strpos($html, 'data-pinned-row="'.$t->id.'"') ?: strpos($html, route('chat.show', $t)))->all();
    expect($positions[0])->toBeLessThan($positions[1])->and($positions[1])->toBeLessThan($positions[2])->and($positions[2])->toBeLessThan($positions[3]);

    // Urutan baru: t2, t3, t1.
    $this->postJson(route('chat.pins.reorder'), ['ids' => [$t2->id, $t3->id, $t1->id]])->assertOk();
    expect($order())->toBe([$t2->id, $t3->id, $t1->id]);
    // Obrolan yang tidak disematkan diabaikan; yang tidak disebut ikut di belakang.
    $this->postJson(route('chat.pins.reorder'), ['ids' => [$t1->id, $t6->id]])->assertOk();
    expect($order())->toBe([$t1->id, $t2->id, $t3->id]);

    // Batas 5; lepas sematan merapikan nomor urut.
    $this->postJson(route('chat.pin', $t4))->assertOk();
    $this->postJson(route('chat.pin', $t5))->assertOk();
    $this->postJson(route('chat.pin', $t6))->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Maksimal 5'));
    $this->postJson(route('chat.pin', $t1))->assertOk()->assertJson(['pinned' => false]);
    expect(ChatParticipant::where('user_id', $this->ani->id)->whereNotNull('pin_order')->orderBy('pin_order')->pluck('pin_order')->all())->toBe([1, 2, 3, 4]);

    // Sematan itu pribadi: lawan bicara tidak ikut tersematkan.
    chatAs($this->budi);
    expect(ChatParticipant::where('user_id', $this->budi->id)->whereNotNull('pin_order')->count())->toBe(0);
    $this->get(route('chat.index'))->assertOk()->assertDontSee('Disematkan');
    $this->postJson(route('chat.pin', $t2))->assertForbidden(); // bukan peserta
});

it('does not let HR pin a conversation they only monitor', function () {
    $thread = directChat($this, $this->ani, $this->budi);
    say($this, $thread, 'rahasia')->assertOk();
    chatAs($this->hr);
    $this->get(route('chat.show', $thread))->assertOk();
    $this->postJson(route('chat.pin', $thread))->assertForbidden();
});

it('marks a chat unread or read from the list, with a green dot, and clears the mark when opened', function () {
    $thread = directChat($this, $this->ani, $this->budi);
    say($this, $thread, 'Halo Budi')->assertOk();

    chatAs($this->budi);
    $this->get(route('chat.show', $thread))->assertOk();
    expect(User::find($this->budi->id)->unreadChatCount())->toBe(0);

    // Tandai belum dibaca: titik hijau, dihitung di menu / tombol obrolan, aksi cepat menawarkan "Sudah dibaca".
    $this->postJson(route('chat.mark', $thread), ['unread' => true])->assertOk()->assertJson(['unread' => true]);
    expect(User::find($this->budi->id)->unreadChatCount())->toBe(1);
    nextRequest(); // pengguna dibaca ulang seperti permintaan HTTP sungguhan (hitungan dicache per objek pengguna)
    $this->getJson(route('chat.unread'))->assertExactJson(['unread' => 1]);
    $this->get(route('chat.index'))->assertOk()->assertSee('Ditandai belum dibaca')->assertSee('Sudah dibaca')
        ->assertSee('data-pin-url="'.route('chat.pin', $thread).'"', false)->assertSee('data-unread="1"', false);

    // Membuka obrolan menghapus tanda.
    $this->get(route('chat.show', $thread))->assertOk();
    expect(User::find($this->budi->id)->unreadChatCount())->toBe(0);
    $this->get(route('chat.index'))->assertOk()->assertDontSee('Ditandai belum dibaca')->assertSee('Belum dibaca');

    // Pesan baru yang belum dibuka -> "Sudah dibaca" menganggap semuanya terbaca.
    chatAs($this->ani);
    say($this, $thread, 'Satu lagi')->assertOk();
    chatAs($this->budi);
    expect(User::find($this->budi->id)->unreadChatCount())->toBe(1);
    $this->postJson(route('chat.mark', $thread), ['unread' => false])->assertOk();
    expect(User::find($this->budi->id)->unreadChatCount())->toBe(0);

    // Hanya peserta; nilai wajib.
    $this->postJson(route('chat.mark', $thread), [])->assertStatus(422);
    chatAs($this->hr);
    $this->postJson(route('chat.mark', $thread), ['unread' => true])->assertForbidden();
    $this->get(route('chat.index', ['semua' => 1]))->assertOk()->assertDontSee('data-row-actions', false);
});

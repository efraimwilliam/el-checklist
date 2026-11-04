<?php

use App\Chronology\ReportText;
use App\Enums\Role;
use App\Models\ChronologyReport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Room;
use App\Models\RoomIssue;
use App\Models\User;

function gro(Department $department, string $name = 'Eka'): User
{
    return User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($department)->create(['name' => $name, 'position_title' => 'Guest Relations Officer'])->id]);
}

function reportPayload(array $extra = []): array
{
    return $extra + [
        'nationality' => 'Australia', 'booking_source' => 'Alaric', 'check_in' => '2026-11-02', 'check_out' => '2026-11-05', 'intent' => 'draft',
        'guests' => [['name' => 'Giuseppe Mazzarella', 'room_number' => '1114'], ['name' => 'Ida Gervasi', 'room_number' => '1116']],
        'entries' => [
            ['body' => 'On 2 November 2026 the guest asked the Front Office team regarding the room sign.', 'occurred_at' => '2026-11-02T20:00'],
            ['body' => 'GRO (EKA) explained the free upgrade.', 'occurred_at' => ''],
            ['body' => 'Around 22:00 PM Mrs Ida complained regarding the noise.', 'occurred_at' => ''],
        ],
    ];
}

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->author = gro($this->fo);
});

it('lets staff write a draft chronology report with guests and ordered points', function () {
    loggedIn($this->author);
    $this->get(route('chronology.create'))->assertOk()->assertSee('Guest Name')->assertSee('Nationality')->assertSee('Source of Booking')->assertSee('data-repeater', false);

    $this->post(route('chronology.store'), reportPayload())->assertRedirect();
    $report = ChronologyReport::firstOrFail();
    expect($report->status)->toBe('draft')->and($report->author_id)->toBe($this->author->id)->and($report->department_id)->toBe($this->fo->id)
        ->and($report->guests->pluck('name')->all())->toBe(['Giuseppe Mazzarella', 'Ida Gervasi'])->and($report->guests->pluck('room_number')->all())->toBe(['1114', '1116'])
        ->and($report->entries->pluck('sort')->all())->toBe([1, 2, 3])->and($report->entries->first()->body)->toContain('room sign')
        ->and($report->check_out->toDateString())->toBe('2026-11-05');
});

it('builds the report text exactly like the template, with name and position of the author', function () {
    loggedIn($this->author);
    $this->post(route('chronology.store'), reportPayload());
    $report = ChronologyReport::firstOrFail();

    $expected = implode("\n", [
        '*CHRONOLOGY REPORT*', '',
        'Guest Name : Giuseppe Mazzarella', 'Room Number : 1114', 'Guest Name : Ida Gervasi', 'Room Number : 1116',
        'Nationality : Australia', 'Length of stay : 02 November 2026 - 05 November 2026', 'Source of Booking : Alaric', '',
        '1. On 2 November 2026 the guest asked the Front Office team regarding the room sign.',
        '2. GRO (EKA) explained the free upgrade.',
        '3. Around 22:00 PM Mrs Ida complained regarding the noise.', '',
        'This chronology report is made truthfully and to the best of my knowledge.', '',
        'Warm regards,', '', '*Eka', 'Guest Relations Officer',
    ]);
    expect(ReportText::plain($report))->toBe($expected);

    $this->get(route('chronology.show', $report))->assertOk()->assertSee('CHRONOLOGY REPORT')->assertSee('Giuseppe Mazzarella')->assertSee('1116')->assertSee('Warm regards,')
        ->assertSee('Guest Relations Officer')->assertSee('Cetak / simpan PDF')->assertSee('Salin teks')->assertSee('data-copy-source', false);
});

it('validates guests, points and stay dates and drops empty rows', function () {
    loggedIn($this->author);
    $this->post(route('chronology.store'), reportPayload(['guests' => [['name' => '', 'room_number' => '1114']]]))->assertSessionHasErrors('guests');
    $this->post(route('chronology.store'), reportPayload(['entries' => [['body' => '  ', 'occurred_at' => '']]]))->assertSessionHasErrors('entries');
    $this->post(route('chronology.store'), reportPayload(['check_out' => '2026-11-01']))->assertSessionHasErrors('check_out');
    $this->post(route('chronology.store'), reportPayload(['check_in' => '']))->assertSessionHasErrors('check_in');
    expect(ChronologyReport::count())->toBe(0);

    $this->post(route('chronology.store'), reportPayload(['guests' => [['name' => 'Ida', 'room_number' => '1116'], ['name' => '', 'room_number' => '']], 'entries' => [['body' => 'Satu', 'occurred_at' => ''], ['body' => '', 'occurred_at' => '']]]))->assertRedirect();
    $report = ChronologyReport::firstOrFail();
    expect($report->guests)->toHaveCount(1)->and($report->entries)->toHaveCount(1);
});

it('edits a draft, reorders points, then locks it when finalized and lets HR reopen it', function () {
    loggedIn($this->author);
    $this->post(route('chronology.store'), reportPayload());
    $report = ChronologyReport::firstOrFail();

    $this->get(route('chronology.edit', $report))->assertOk()->assertSee('room sign');
    $this->put(route('chronology.update', $report), reportPayload(['entries' => [['body' => 'Second first', 'occurred_at' => ''], ['body' => 'Then the first', 'occurred_at' => '']]]))->assertRedirect();
    expect($report->refresh()->entries->pluck('body')->all())->toBe(['Second first', 'Then the first']);

    $this->put(route('chronology.update', $report), reportPayload(['intent' => 'final']))->assertRedirect();
    expect($report->refresh()->status)->toBe('final')->and($report->finalized_at)->not->toBeNull();

    // Final = terkunci.
    $this->get(route('chronology.edit', $report))->assertForbidden();
    $this->put(route('chronology.update', $report), reportPayload())->assertForbidden();
    $this->delete(route('chronology.destroy', $report))->assertForbidden();
    $this->post(route('chronology.reopen', $report))->assertForbidden();
    $this->post('/logout');

    loggedIn(User::factory()->role(Role::Hr)->create());
    $this->post(route('chronology.reopen', $report))->assertRedirect();
    expect($report->refresh()->status)->toBe('draft')->and($report->finalized_at)->toBeNull();
});

it('shows a report only to its author, the HOD of the author department, HR, GM and the Administrator', function () {
    loggedIn($this->author);
    $this->post(route('chronology.store'), reportPayload());
    $report = ChronologyReport::firstOrFail();
    $this->post('/logout');

    $allowed = [User::factory()->hodOf($this->fo)->create(), User::factory()->role(Role::Hr)->create(), User::factory()->role(Role::Gm)->create(), User::factory()->role(Role::Admin)->create()];
    foreach ($allowed as $user) {
        loggedIn($user);
        $this->get(route('chronology.show', $report))->assertOk();
        $this->get(route('chronology.index'))->assertOk()->assertSee('Giuseppe Mazzarella');
        $this->post('/logout');
    }

    foreach ([gro($this->fo, 'Lain'), User::factory()->hodOf($this->hk)->create(), gro($this->hk, 'Sari')] as $user) {
        loggedIn($user);
        $this->get(route('chronology.show', $report))->assertForbidden();
        $this->get(route('chronology.index'))->assertOk()->assertDontSee('Giuseppe Mazzarella');
        $this->post('/logout');
    }
});

it('gives managers a search and status filter but keeps the staff list plain', function () {
    loggedIn($this->author);
    $this->post(route('chronology.store'), reportPayload());
    $this->post(route('chronology.store'), reportPayload(['intent' => 'final', 'guests' => [['name' => 'Other Guest', 'room_number' => '2001']]]));
    $this->get(route('chronology.index'))->assertOk()->assertDontSee('id="chronology-search"', false);
    $this->post('/logout');

    loggedIn(User::factory()->role(Role::Hr)->create());
    $this->get(route('chronology.index'))->assertOk()->assertSee('id="chronology-search"', false);
    $this->get(route('chronology.index', ['q' => '2001']))->assertOk()->assertSee('Other Guest')->assertDontSee('Giuseppe');
    $this->get(route('chronology.index', ['q' => 'Ida']))->assertOk()->assertSee('Giuseppe')->assertDontSee('Other Guest');
    $this->get(route('chronology.index', ['status' => 'final']))->assertOk()->assertSee('Other Guest')->assertDontSee('Giuseppe');
    $this->get(route('chronology.index', ['q' => 'zzzzz']))->assertOk()->assertSee('Tidak ada laporan yang cocok.');
});

it('lets the author delete a draft and prefills the room and link when started from a room issue', function () {
    $room = Room::create(['number' => '305', 'floor' => '3', 'sort' => 1]);
    $issue = RoomIssue::create(['room_id' => $room->id, 'source' => 'tamu', 'description' => 'AC panas', 'priority' => 'biasa', 'reported_by' => $this->author->id, 'assigned_department_id' => $this->fo->id, 'status' => 'baru']);

    loggedIn($this->author);
    $this->get(route('chronology.create', ['kendala' => $issue->id]))->assertOk()->assertSee('value="305"', false)->assertSee('name="room_issue_id" value="'.$issue->id.'"', false);
    $this->post(route('chronology.store'), reportPayload(['room_issue_id' => $issue->id]));
    $report = ChronologyReport::firstOrFail();
    expect($report->room_issue_id)->toBe($issue->id);
    $this->get(route('chronology.show', $report))->assertOk()->assertSee('#'.$issue->id);

    $this->delete(route('chronology.destroy', $report))->assertRedirect(route('chronology.index'));
    expect(ChronologyReport::count())->toBe(0)->and(\App\Models\ChronologyReportEntry::count())->toBe(0);
});

function pastedReport(): string
{
    // Seperti hasil salin WhatsApp: penanda tebal, word joiner (U+2060) di awal baris, spasi ganda, baris lanjutan.
    return "*CHRONOLOGY REPORT*\n\nGuest Name : Giuseppe Mazzarella\nRoom Number : 1114\nGuest Name : Ida Gervasi\nRoom Number : 1116\nNationality : Australia\nLength of stay : 02 November 2026 - 05 November 2026\nSource of Booking : Alaric\n\n"
        ."1. On 2 November 2026 30 minute after check in around 20:00 PM the #1116 guest  (Mrs Ida) ask the Front Office team.\n"
        ."2. \u{2060}GRO (EKA) explained that Mr David getting the free upgrade.\n"
        ."3. \u{2060}However, around 22:00 PM Mrs Ida came to the lobby.\n   Her son can’t sleep and crying.\n"
        ."4. \u{2060}The following day on 3 November 2026 , GRO (EKA) discussed with the Front Office Manager.\n\n"
        ."This chronology report is made truthfully and to the best of my knowledge .\n\nWarm regards,\n\n*Eka\nGuest Relations Officer\n";
}

it('reads a pasted report into guests, stay dates, source, numbered points and the signature', function () {
    $parsed = \App\Chronology\ReportParser::parse(pastedReport());

    expect($parsed['guests'])->toBe([['name' => 'Giuseppe Mazzarella', 'room_number' => '1114'], ['name' => 'Ida Gervasi', 'room_number' => '1116']])
        ->and($parsed['nationality'])->toBe('Australia')->and($parsed['check_in'])->toBe('2026-11-02')->and($parsed['check_out'])->toBe('2026-11-05')->and($parsed['booking_source'])->toBe('Alaric')
        ->and($parsed['entries'])->toHaveCount(4)->and($parsed['entries'][0])->toContain('guest (Mrs Ida) ask')->and($parsed['entries'][1])->toStartWith('GRO (EKA)')
        ->and($parsed['entries'][2])->toBe('However, around 22:00 PM Mrs Ida came to the lobby. Her son can’t sleep and crying.')
        ->and($parsed['author'])->toBe('Eka')->and($parsed['position'])->toBe('Guest Relations Officer')->and($parsed['warnings'])->toBe([]);
});

it('understands other date styles and reports what it could not read', function () {
    $id = \App\Chronology\ReportParser::parse("Guest Name : A\nLength of stay : 2 Nov 2026 s/d 5 Nov 2026\n1. Satu");
    expect($id['check_in'])->toBe('2026-11-02')->and($id['check_out'])->toBe('2026-11-05');
    $slash = \App\Chronology\ReportParser::parse("Guest Name : A\nLength of stay : 02/11/2026 - 05/11/2026\n1. Satu");
    expect($slash['check_in'])->toBe('2026-11-02')->and($slash['check_out'])->toBe('2026-11-05');
    $month = \App\Chronology\ReportParser::parse("Guest Name : A\nLength of stay : 02 Desember 2026 - 05 Desember 2026\n1. Satu");
    expect($month['check_in'])->toBe('2026-12-02');
    $short = \App\Chronology\ReportParser::parse("Guest Name : A\nLength of stay : 02 - 05 November 2026\n1. Satu");
    expect($short['check_in'])->toBe('2026-11-02')->and($short['check_out'])->toBe('2026-11-05');

    $bad = \App\Chronology\ReportParser::parse('hanya sepotong kalimat tanpa format');
    // Teks tanpa format: nama tamu dan tanggal dilaporkan tidak terbaca; kalimatnya dipakai sebagai satu poin (tanpa nomor, tiap paragraf = satu poin).
    expect($bad['warnings'])->toHaveCount(2)->and($bad['guests'])->toBe([])->and($bad['check_in'])->toBeNull()->and($bad['entries'])->toBe(['hanya sepotong kalimat tanpa format']);
    $empty = \App\Chronology\ReportParser::parse('Guest Name : A');
    expect($empty['warnings'])->toHaveCount(2)->and($empty['entries'])->toBe([]);

    // Tanpa nomor: tiap paragraf menjadi satu poin.
    $plain = \App\Chronology\ReportParser::parse("Guest Name : A\nLength of stay : 02 November 2026\n\nParagraf pertama.\n\nParagraf kedua.");
    expect($plain['entries'])->toBe(['Paragraf pertama.', 'Paragraf kedua.']);
});

it('reads back its own copied text (copy then paste gives the same report)', function () {
    loggedIn($this->author);
    $this->post(route('chronology.store'), reportPayload());
    $report = ChronologyReport::firstOrFail();

    $parsed = \App\Chronology\ReportParser::parse(ReportText::plain($report));
    expect($parsed['guests'])->toHaveCount(2)->and($parsed['entries'])->toBe($report->entries->pluck('body')->all())
        ->and($parsed['check_in'])->toBe('2026-11-02')->and($parsed['author'])->toBe('Eka')->and($parsed['warnings'])->toBe([]);
});

it('saves a pasted report straight away as a draft owned by the person who pasted it', function () {
    loggedIn($this->author);
    $this->get(route('chronology.create'))->assertOk()->assertSee('Tempel dari WhatsApp / Word')->assertSee('Simpan langsung sebagai draft');

    $this->post(route('chronology.paste'), ['text' => pastedReport(), 'mode' => 'save'])->assertRedirect();
    $report = ChronologyReport::firstOrFail();
    expect($report->status)->toBe('draft')->and($report->author_id)->toBe($this->author->id)->and($report->guests)->toHaveCount(2)->and($report->entries)->toHaveCount(4)
        ->and($report->nationality)->toBe('Australia')->and($report->booking_source)->toBe('Alaric')->and($report->check_in->toDateString())->toBe('2026-11-02')->and($report->check_out->toDateString())->toBe('2026-11-05')
        ->and($report->entries->pluck('sort')->all())->toBe([1, 2, 3, 4]);
    $this->get(route('chronology.show', $report))->assertOk()->assertSee('Giuseppe Mazzarella')->assertSee('Her son can’t sleep and crying.');
});

it('fills the form for review when asked, and falls back to it when required data is missing', function () {
    loggedIn($this->author);
    $this->post(route('chronology.paste'), ['text' => pastedReport(), 'mode' => 'fill'])->assertOk()
        ->assertSee('Teks terbaca. Periksa isinya di bawah, lalu simpan.')->assertSee('Giuseppe Mazzarella')->assertSee('value="1116"', false)->assertSee('2 tamu · 4 poin', false)->assertSee('Penulis di teks: Eka');
    expect(ChronologyReport::count())->toBe(0);

    // Tanggal tidak terbaca: tidak disimpan langsung, form terisi seadanya dengan peringatan.
    $noDate = str_replace('Length of stay : 02 November 2026 - 05 November 2026', 'Length of stay : -', pastedReport());
    $this->post(route('chronology.paste'), ['text' => $noDate, 'mode' => 'save'])->assertOk()
        ->assertSee('Sebagian belum terbaca, lengkapi di bawah')->assertSee('Tanggal menginap tidak terbaca')->assertSee('Giuseppe Mazzarella');
    expect(ChronologyReport::count())->toBe(0);

    $this->post(route('chronology.paste'), ['text' => '', 'mode' => 'save'])->assertSessionHasErrors('text');
});

it('shows the delete button in the top bar only to those who may delete the report', function () {
    loggedIn($this->author);
    $this->post(route('chronology.store'), reportPayload());
    $report = ChronologyReport::firstOrFail();
    $this->get(route('chronology.show', $report))->assertOk()->assertSee('action="'.route('chronology.destroy', $report).'"', false)->assertSee('Hapus laporan ini?');

    // Final: penulis tidak lagi bisa menghapus, Administrator tetap bisa.
    $report->update(['status' => 'final', 'finalized_at' => now()]);
    $this->get(route('chronology.show', $report))->assertOk()->assertDontSee('action="'.route('chronology.destroy', $report).'"', false);
    $this->post('/logout');

    loggedIn(User::factory()->role(Role::Hr)->create());
    $this->get(route('chronology.show', $report))->assertOk()->assertDontSee('action="'.route('chronology.destroy', $report).'"', false);
    $this->post('/logout');

    loggedIn(User::factory()->role(Role::Admin)->create());
    $this->get(route('chronology.show', $report))->assertOk()->assertSee('action="'.route('chronology.destroy', $report).'"', false);
});

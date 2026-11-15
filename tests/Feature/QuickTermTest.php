<?php

use App\Chat\QuickEntry;
use App\Enums\Role;
use App\Models\IssueCategory;
use App\Models\QuickTerm;
use App\Models\Room;

beforeEach(function () {
    Room::create(['number' => '1206', 'sort' => 1]);
    $this->admin = userWithRole(Role::Admin);
});

function readQuick(string $text): array
{
    return app()->make(QuickEntry::class)->read($text); // instansi baru: kamus dibaca ulang
}

it('starts with the dictionary taken from the group chat', function () {
    expect(QuickTerm::where(['group' => 'request_word', 'term' => 'req'])->exists())->toBeTrue()
        ->and(QuickTerm::where(['group' => 'item', 'target' => 'mineral_water', 'term' => 'mw'])->exists())->toBeTrue()
        ->and(QuickTerm::where(['group' => 'issue', 'target' => (string) IssueCategory::where('name', 'AC')->value('id'), 'term' => 'ac'])->exists())->toBeTrue();
});

it('lets the Administrator add words that the chat reader uses right away', function () {
    loggedIn($this->admin);
    $ac = IssueCategory::where('name', 'AC')->firstOrFail();

    expect(readQuick('1206 mohon bawakan lan cable')['type'])->toBeNull();

    // Kata minta baru + barang baru (Lainnya) + tanda kendala baru.
    $this->post(route('admin.quick-terms.store'), ['group' => 'request_word', 'term' => '  Mohon Bawakan '])->assertRedirect();
    $this->post(route('admin.quick-terms.store'), ['group' => 'item', 'target' => 'other', 'term' => 'lan cable'])->assertRedirect();
    $this->post(route('admin.quick-terms.store'), ['group' => 'issue', 'target' => $ac->id, 'term' => 'freezer'])->assertRedirect();
    expect(QuickTerm::where(['group' => 'request_word', 'term' => 'mohon bawakan'])->exists())->toBeTrue();

    $read = readQuick('1206 mohon bawakan 2 lan cable');
    expect($read['type'])->toBe('request')->and($read['items'][0]['kind'])->toBe('other')->and($read['items'][0]['name'])->toBe('lan cable')->and($read['items'][0]['quantity'])->toBe(2);
    expect(readQuick('1206 freezernya tidak bekerja')['category']?->name)->toBe('AC');

    // Coba kalimat di halaman kamus.
    $this->get(route('admin.quick-terms.index', ['coba' => '1206 mohon bawakan lan cable']))->assertOk()->assertSee('Lainnya: lan cable');
});

it('removes words and rejects duplicates, too-short words, and unknown targets', function () {
    loggedIn($this->admin);

    $this->from(route('admin.quick-terms.index'))->post(route('admin.quick-terms.store'), ['group' => 'request_word', 'term' => 'req'])
        ->assertRedirect(route('admin.quick-terms.index'))->assertSessionHasErrors(['term' => '"req" sudah ada di sini.'], null, 'quick');
    $this->post(route('admin.quick-terms.store'), ['group' => 'urgent', 'term' => 'x'])->assertSessionHasErrors('term', null, 'quick');
    $this->post(route('admin.quick-terms.store'), ['group' => 'item', 'target' => 'pizza', 'term' => 'pizza'])->assertSessionHasErrors('term', null, 'quick');

    $req = QuickTerm::where(['group' => 'request_word', 'term' => 'req'])->firstOrFail();
    $this->delete(route('admin.quick-terms.destroy', $req))->assertRedirect();
    expect(readQuick('1206 req lan cable')['type'])->toBeNull();
});

it('is only for the Administrator', function () {
    loggedIn(userWithRole(Role::Hr));

    $this->get(route('admin.quick-terms.index'))->assertForbidden();
    $this->post(route('admin.quick-terms.store'), ['group' => 'request_word', 'term' => 'tolong kirim'])->assertForbidden();
});

it('lets the Administrator add a request kind with its own department and words', function () {
    $it = \App\Models\Department::factory()->create(['name' => 'IT']);
    $hk = \App\Models\Department::factory()->create(['name' => 'Housekeeping']);
    loggedIn($this->admin);

    $this->post(route('admin.quick-terms.kinds.store'), ['label' => 'Kabel LAN', 'department_id' => $it->id, 'has_quantity' => '1', 'terms' => 'lan cable, Kabel LAN , x'])
        ->assertRedirect(route('admin.quick-terms.index').'#kamus-item-kabel_lan');
    $kind = \App\Models\GuestRequestKind::where('key', 'kabel_lan')->firstOrFail();
    expect($kind->label)->toBe('Kabel LAN')->and($kind->department_id)->toBe($it->id)
        ->and(QuickTerm::where(['group' => 'item', 'target' => 'kabel_lan'])->orderBy('term')->pluck('term')->all())->toBe(['kabel lan', 'lan cable'])
        ->and(\App\Models\GuestRequestKind::orderByDesc('sort')->value('key'))->toBe('other');

    // Ketik bebas membaca jenis baru, ke IT; jenis lama tetap ke Housekeeping.
    $read = readQuick('1206 req 2 lan cable + 1 towel');
    expect(collect($read['items'])->pluck('kind')->all())->toBe(['towel', 'kabel_lan']);
    expect(\App\Models\GuestRequest::kinds()->departmentFor('kabel_lan'))->toBe($it->id)->and(\App\Models\GuestRequest::kinds()->departmentFor('towel'))->toBe($hk->id);

    // Dialog Catat permintaan: jenis baru bisa dipilih dan "Sesuai jenis" mengikuti departemennya.
    $this->get(route('permintaan.index'))->assertOk()->assertSee('value="kabel_lan"', false)->assertSee('Sesuai jenis (bawaan)');
    $this->post(route('permintaan.store'), ['room' => '1206', 'kinds' => ['kabel_lan', 'towel'], 'qty' => ['kabel_lan' => 2], 'priority' => 'biasa'])->assertRedirect();
    $requests = \App\Models\GuestRequest::orderBy('id')->get()->keyBy('kind');
    expect($requests['kabel_lan']->assigned_department_id)->toBe($it->id)->and($requests['kabel_lan']->label())->toBe('2× Kabel LAN')
        ->and($requests['towel']->assigned_department_id)->toBe($hk->id);

    // Duplikat nama ditolak.
    $this->post(route('admin.quick-terms.kinds.store'), ['label' => 'Kabel LAN'])->assertSessionHasErrors('label', null, 'kind');
});

it('renames or deactivates a kind without touching old requests, and keeps Lainnya active', function () {
    loggedIn($this->admin);
    $room = Room::where('number', '1206')->first();
    $old = \App\Models\GuestRequest::create(['room_id' => $room->id, 'kind' => 'pillow', 'quantity' => 2, 'priority' => 'biasa']);
    $pillow = \App\Models\GuestRequestKind::where('key', 'pillow')->firstOrFail();

    $this->put(route('admin.quick-terms.kinds.update', $pillow), ['label' => 'Bantal & guling', 'has_quantity' => '1'])->assertRedirect();
    expect($pillow->refresh()->is_active)->toBeFalse()->and($old->refresh()->label())->toBe('2× Bantal & guling');
    expect(collect(readQuick('1206 req 2 pillow')['items'])->pluck('kind')->all())->not->toContain('pillow');
    $this->get(route('permintaan.index'))->assertOk()->assertDontSee('value="pillow"', false);

    $other = \App\Models\GuestRequestKind::where('key', 'other')->firstOrFail();
    $this->put(route('admin.quick-terms.kinds.update', $other), ['label' => 'Lainnya'])->assertRedirect();
    expect($other->refresh()->is_active)->toBeTrue();
});

it('lets the Administrator add and edit issue categories from the dictionary', function () {
    $it = \App\Models\Department::factory()->create(['name' => 'IT']);
    loggedIn($this->admin);

    $this->post(route('admin.quick-terms.categories.store'), ['name' => 'IT & jaringan', 'department_id' => $it->id, 'terms' => 'wifi lemot, router, lan'])->assertRedirect();
    $category = IssueCategory::where('name', 'IT & jaringan')->firstOrFail();
    expect($category->department_id)->toBe($it->id)->and($category->is_active)->toBeTrue()
        ->and(QuickTerm::where(['group' => 'issue', 'target' => (string) $category->id])->orderBy('term')->pluck('term')->all())->toBe(['lan', 'router', 'wifi lemot']);

    $read = readQuick('1206 router mati sejak pagi');
    expect($read['type'])->toBe('issue')->and($read['category']?->name)->toBe('IT & jaringan');
    $this->get(route('admin.quick-terms.index'))->assertOk()->assertSee('IT &amp; jaringan', false)->assertSee('Ubah kategori');
    $this->get(route('kamar.issues.index'))->assertOk()->assertSee('IT &amp; jaringan', false);

    // Nonaktif: kata-katanya tidak lagi dibaca; nama ganda ditolak.
    $this->put(route('admin.quick-terms.categories.update', $category), ['name' => 'IT & jaringan', 'department_id' => $it->id])->assertRedirect();
    expect($category->refresh()->is_active)->toBeFalse()->and(readQuick('1206 router mati sejak pagi')['type'])->toBeNull();
    $this->post(route('admin.quick-terms.categories.store'), ['name' => 'AC'])->assertSessionHasErrors('name', null, 'category');

    $this->post(route('logout'));
    app('auth')->forgetGuards();
    loggedIn(userWithRole(Role::Hr));
    $this->post(route('admin.quick-terms.categories.store'), ['name' => 'Taman'])->assertForbidden();
});

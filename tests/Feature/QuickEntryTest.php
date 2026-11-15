<?php

use App\Chat\QuickEntry;
use App\Models\IssueCategory;
use App\Models\Room;

beforeEach(function () {
    foreach (['102', '2219', '1109', '1205', '1206', '2221', '2232', '1102', '1203', '3155', '2217'] as $i => $number) {
        Room::create(['number' => $number, 'sort' => $i]);
    }
    foreach (['AC', 'Air & sanitasi', 'Kebersihan & linen', 'Minibar & amenities', 'Lainnya'] as $i => $name) {
        IssueCategory::firstOrCreate(['name' => $name], ['sort' => 50 + $i]);
    }
});

function quick(string $text): array
{
    $read = app(QuickEntry::class)->read($text);

    return [
        'type' => $read['type'],
        'room' => $read['room']?->number,
        'items' => collect($read['items'])->map(fn ($i) => $i['quantity'].'×'.$i['kind'])->all(),
        'category' => $read['category']?->name,
        'priority' => $read['priority'],
        'warnings' => $read['warnings'],
    ];
}

it('reads Front Office request messages as written in the WhatsApp group', function (string $text, string $room, array $items) {
    $read = quick($text);
    expect($read['type'])->toBe('request')->and($read['room'])->toBe($room)->and($read['items'])->toBe($items)->and($read['warnings'])->toBe([]);
})->with([
    ['1206 req 2 toilet paper *eka', '1206', ['2×tissue']],
    ['1109 request 4 mw', '1109', ['4×mineral_water']],
    ['Hk dbntu 2221 dbntu firm pillow + 1 extra bathtowel *skma', '2221', ['1×towel', '1×pillow']],
    ['HK Team, 1205 req MUR tka *winde', '1205', ['1×mur']],
    ['hk 1205 req 2 minrl water *skm', '1205', ['2×mineral_water']],
    ['HK team, 1206 req 4x toilet rolls + pick up laundry', '1206', ['4×tissue', '1×laundry']],
    ['Att HK tlg kirim jemuran baju, kamar 1203', '1203', ['1×drying_rack']],
    ['HK dibantu 1203 minta hanging rack untuk dibalkon *eka', '1203', ['1×drying_rack']],
    ['Dear hk dibantu 1203 req pick up laundry *Vindy', '1203', ['1×laundry']],
    ['dbntu pickup laundry 1205 dilobby *skma', '1205', ['1×laundry']],
    ['HK team, #1206 req inner duvet as bed topper *ulan', '1206', ['1×other']],
    ['1205 need extra 6 hanger guest keluar *eka', '1205', ['6×other']],
]);

it('warns when the room number is not in the room data', function () {
    $read = quick('2128 req pick up laundry *eka');

    expect($read['type'])->toBe('request')->and($read['room'])->toBeNull()->and($read['items'])->toBe(['1×laundry'])
        ->and($read['warnings'])->toBe(['Kamar 2128 tidak ada di data kamar. Periksa lagi nomornya.']);
});

it('reads issue messages with a category guess and urgency', function (string $text, string $room, string $category, string $priority) {
    $read = quick($text);
    expect($read['type'])->toBe('issue')->and($read['room'])->toBe($room)->and($read['category'])->toBe($category)->and($read['priority'])->toBe($priority);
})->with([
    ['Engginering Team dibantu #2232 air kamar mandi ngeblock...tamu didlam kamar *tfk', '2232', 'Air & sanitasi', 'mendesak'],
    ['ENG team dibantu cek AC kamar 1102 info dari tamunya leaking, dan HK mohon dibantu mop lantainya', '1102', 'AC', 'biasa'],
    ['3155 Info minibar tidak mau dingin sama skali', '3155', 'Minibar & amenities', 'biasa'],
    ['2217 AC nya tidak dingin, tamu menunggu di kamar', '2217', 'AC', 'mendesak'],
    ['1203 ada semut di kamar mandi', '1203', 'Kebersihan & linen', 'biasa'],
]);

it('ignores messages that are not a request or an issue', function (string $text) {
    expect(quick($text)['type'])->toBeNull();
})->with([
    '1203 CO *anggun',
    'Copy',
    'Done',
    'Arrival on 02/11/2026 : 6 Nights',
    'Late CO 18.00 ya',
    '1116 C.O early, due to late night flight *Vindy',
    'HK team apakah laundry 1203 sudah ready? *eka',
    '1204 Q for shower 1203 *skma',
    'fyi hk team, room 1206 dipakai shower room ex 1109 *agus',
]);

it('records unknown requested items as Lainnya with their name', function (string $text, string $room, string $name, int $quantity) {
    $read = app(QuickEntry::class)->read($text);

    expect($read['type'])->toBe('request')->and($read['room']?->number)->toBe($room)->and($read['items'])->toHaveCount(1)
        ->and($read['items'][0]['kind'])->toBe('other')->and($read['items'][0]['name'])->toBe($name)->and($read['items'][0]['quantity'])->toBe($quantity);
})->with([
    ['102 req lan cable', '102', 'lan cable', 1],
    ['HK team dibantu 1203 req 2 kettle guest diroom *eka', '1203', 'kettle', 2],
    ['1205 minta adaptor colokan, tamu menunggu', '1205', 'adaptor colokan', 1],
    ['1206 need extra hair dryer please *vindy', '1206', 'hair dryer', 1],
    ['HK team, #1206 req inner duvet as bed topper *ulan', '1206', 'duvet, bed topper', 1],
    ['1205 req bathrobe dbntu y *skm', '1205', 'bathrobe', 1],
    ['Team dibantu 1203 req twin bed (last minute) tamu dikamar *eka', '1203', 'twin bed', 1],
]);

it('reads the abbreviations used by Front Office', function () {
    expect(quick('Team dibantu 1203 req 2 BT *eka')['items'])->toBe(['2×towel'])
        ->and(quick('Team dibantu 1205 req add eb thank youu')['items'])->toBe(['1×extra_bed'])
        ->and(quick('HK team, dibantu 1206 req bottles of water *ulan')['items'])->toBe(['1×mineral_water'])
        ->and(quick('Btw untuk 1206 sudah request dari Pagi dan saya sudah info')['type'])->toBeNull()
        ->and(quick('2219 req extra 4 bottle water guest diroom *eka')['items'])->toBe(['4×mineral_water'])
        ->and(quick('eng team 2232 air kamar mandinya kecil, minta tolong di cek *oka')['type'])->toBeNull();
});

it('does not record items after "no need", and needs a request word for unknown items', function () {
    expect(quick('1205 req amenities, no need MUR *winde')['items'])->toBe(['1×amenities'])
        ->and(quick('102 lan cable sudah dicek')['type'])->toBeNull()
        ->and(quick('1203 req')['type'])->toBeNull();
});

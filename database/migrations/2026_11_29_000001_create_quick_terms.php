<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Kamus awal (dari pesan grup Room Division Mei-Nov 2026). "*" di akhir = boleh diikuti huruf apa pun ("towel*" = towel, towels). */
    private const ITEMS = [
        'mineral_water' => ['air mineral', 'mineral water', 'minral water', 'minrl water', 'minerl water', 'minerak water', 'mineral*', 'minerl', 'aqua', 'mw', 'bottle water', 'bottles water', 'bottle of water', 'bottles of water', 'water bottle*'],
        'towel' => ['bath towel*', 'bathtowel*', 'face towel*', 'hand towel*', 'pool towel*', 'towel*', 'handuk', 'bt'],
        'tissue' => ['toilet paper', 'toilet roll*', 'toilet tissue', 'tissue*', 'tisu'],
        'pillow' => ['firm pillow*', 'soft pillow*', 'pillow*', 'bantal'],
        'extra_bed' => ['extra bed', 'extrabed', 'eb'],
        'drying_rack' => ['drying rack', 'hanging rack', 'clothes rack', 'cloth rack', 'jemuran'],
        'laundry' => ['pick up laundry', 'pickup laundry', 'pick-up laundry', 'ambil laundry', 'jemput laundry', 'laundry pick up', 'laundry list', 'laundry bag', 'laundry', 'londri'],
        'mur' => ['make up room', 'makeup room', 'mur'],
        'amenities' => ['amenit*', 'dental kit', 'shampoo', 'sabun', 'soap', 'coffee', 'kopi', 'tea', 'teh', 'creamer', 'sugar', 'gula', 'slipper*', 'sandal'],
        'other' => ['duvet', 'bed topper', 'blanket*', 'selimut', 'hanger*', 'baby cot', 'babycot', 'baby coat', 'iron', 'setrika', 'umbrella', 'payung', 'ice bucket', 'es batu'],
    ];

    private const ISSUES = [
        'Minibar & amenities' => ['minibar', 'mini bar', 'kulkas', 'fridge'],
        'AC' => ['ac', 'aircon*', 'air con', 'air conditioner', 'tidak dingin', 'kurang dingin', 'gak dingin', 'ga dingin', 'not cold'],
        'Air & sanitasi' => ['bocor', 'leak*', 'mampet', 'ngeblock', 'ngeblok', 'clog*', 'tersumbat', 'flush', 'keran', 'kran', 'wastafel', 'wash basin', 'shower', 'air panas', 'hot water', 'toilet', 'bathtub', 'saluran'],
        'Listrik & lampu' => ['lampu', 'listrik', 'lamp*', 'light', 'lights', 'stop kontak', 'socket', 'korslet'],
        'TV & elektronik' => ['tv', 'remote', 'wifi', 'wi-fi', 'internet', 'telepon', 'channel'],
        'Perabot' => ['pintu', 'door', 'kursi', 'chair', 'meja', 'lemari', 'wardrobe', 'curtain', 'gorden', 'gordin', 'headboard', 'ranjang', 'kasur', 'goyang', 'macet', 'patah'],
        'Kebersihan & linen' => ['kotor', 'dirty', 'bau', 'smell*', 'rambut', 'hair', 'semut', 'kecoa', 'nyamuk', 'mosquito*', 'ant', 'ants', 'cockroach*', 'noda', 'stain', 'jamur', 'lembab'],
        'Lainnya' => ['rusak', 'tidak berfungsi', 'tidak bisa', 'not working', 'broken', 'error'],
    ];

    private const WORDS = [
        'request_word' => ['req', 'request', 'minta', 'kirim', 'antar', 'bring', 'need', 'tambah'],
        'urgent' => ['segera', 'urgent', 'asap', 'cepat', 'menunggu', 'waiting', 'tamu di kamar', 'tamu dikamar', 'tamu di dalam kamar', 'tamu didalam kamar', 'tamu didlam kamar', 'tamu di dlm kamar', 'tamu dalam kamar', 'tamu sudah di kamar', 'tamu sudah dikamar', 'in house', 'inhouse', 'marah', 'angry', 'complain'],
        'ignore' => ['shower room', 'hair dryer', 'hairdryer'],
    ];

    public function up(): void
    {
        // Kamus ketik bebas di obrolan (diatur Administrator di Pengaturan > Fitur > Kamus ketik bebas):
        // request_word = kata minta, item = sebutan barang per jenis (target = kunci jenis), issue = tanda kendala per kategori
        // (target = id kategori), urgent = tanda mendesak, ignore = frasa yang tidak dianggap kendala.
        Schema::create('quick_terms', function (Blueprint $table) {
            $table->id();
            $table->string('group', 20);
            $table->string('target', 40)->nullable();
            $table->string('term', 60);
            $table->timestamps();
            $table->unique(['group', 'target', 'term']);
        });

        $now = now();
        $rows = [];
        foreach (self::ITEMS as $kind => $terms) {
            foreach ($terms as $term) {
                $rows[] = ['group' => 'item', 'target' => $kind, 'term' => $term];
            }
        }
        foreach (self::ISSUES as $category => $terms) {
            $id = DB::table('issue_categories')->where('name', $category)->value('id');
            foreach ($id ? $terms : [] as $term) {
                $rows[] = ['group' => 'issue', 'target' => (string) $id, 'term' => $term];
            }
        }
        foreach (self::WORDS as $group => $terms) {
            foreach ($terms as $term) {
                $rows[] = ['group' => $group, 'target' => null, 'term' => $term];
            }
        }
        DB::table('quick_terms')->insert(array_map(fn ($row) => $row + ['created_at' => $now, 'updated_at' => $now], $rows));
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_terms');
    }
};

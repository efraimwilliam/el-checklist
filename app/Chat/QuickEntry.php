<?php

namespace App\Chat;

use App\Models\GuestRequest;
use App\Models\IssueCategory;
use App\Models\QuickTerm;
use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * Membaca pesan obrolan yang diketik bebas (gaya grup WhatsApp Front Office) menjadi permintaan tamu atau kendala.
 * Contoh: "1206 req 2 mineral water + 1 bathtowel *eka" -> permintaan kamar 1206: 2× Air mineral, 1× Handuk.
 *         "2232 air kamar mandi ngeblock, tamu di kamar" -> kendala kamar 2232, Air & sanitasi, mendesak.
 * Kata-katanya (kata minta, sebutan barang, tanda kendala per kategori, tanda mendesak, frasa yang diabaikan) diatur
 * Administrator di "Kamus ketik bebas" (tabel quick_terms). Aturan tetap di kode: nomor kamar, jumlah, "no need X", inisial
 * petugas, nama barang yang tidak dikenal, "laundry" saja = kabar status, pesan "Q" bukan kendala.
 * Dipakai untuk pratinjau saat mengetik dan dibaca ulang saat pesan dikirim, jadi hasilnya selalu sama.
 */
class QuickEntry
{
    /** "no need MUR", "tidak perlu towel": yang disebut sesudahnya bukan permintaan. */
    private const NEGATION = '\b(?:no\s+need|tidak\s+perlu|ga+k?\s+perlu|nggak\s+perlu|cancel)\s+(?:\S+\s*){1,2}';

    /** Nama barang yang tidak dikenal berhenti di kata / tanda ini ("102 req lan cable guest diroom" -> "lan cable"). */
    private const NAME_STOP = '\s(?:guest|tamu|untuk|for|please|pls|plz|thank\w*|thx|tks|tka|ya|yaa|y|team|asap|segera|di|ke|dan|and|karena|krn|sekarang|now|dbntu|dibantu|bantu|ditunggu|bisa|dari|sudah|udah|sdh|hk|eng|engineering|it|fo)\b|[,.;!?@(\n*+&\-]|\s\d{3,4}\b';

    /** Kata pengisi di depan nama barang. */
    private const NAME_FILLER = '^(?:(?:extra|tambahan|lagi|more|another|new|baru|ganti|change|tolong|tlg|mohon|dibantu|dbntu|bantu|untuk|for|the|a|an|some)(?:\s+|$))+';

    /** Jumlah di depan sebutan barang: "2 mineral water", "4x toilet rolls", "1 extra bathtowel". */
    private const QUANTITY = '(?:(\d{1,2})\s*(?:x|pcs|pc|buah|btl|botol)?\s*(?:extra\s+|tambahan\s+)?)?';

    /** Kata sapaan / pengisi yang boleh menyertai balasan ambil / selesai ("copy pak", "otw ya", "done team"). */
    private const CLAIM_FILLER = '(?<![\p{L}])(?:ya+|y|yo|pak|bu|bang|kak|mas|mba|mbak|bro|sis|team|tim|guys|all|dulu|langsung|segera|sekarang|saya|aku|sy|gw|kesana|ke\s+sana|bos|boss)(?![\p{L}])';

    /** @var Collection<string, Collection<int, QuickTerm>>|null kamus per kelompok, dimuat sekali per permintaan */
    private ?Collection $terms = null;

    /**
     * @return array{type: ?string, room: ?Room, room_number: ?string, items: list<array{kind: string, quantity: int, label: string, name: ?string}>,
     *               category: ?IssueCategory, priority: string, warnings: list<string>}
     */
    public function read(string $text): array
    {
        $result = ['type' => null, 'room' => null, 'room_number' => null, 'items' => [], 'category' => null, 'priority' => 'biasa', 'warnings' => []];
        // Inisial petugas di akhir pesan ("*eka", "tka *winde") tidak ikut dibaca.
        $clean = mb_strtolower(trim(preg_replace('/\*\s*[\p{L}.\-]+\s*$/u', '', $text)));
        if ($clean === '') {
            return $result;
        }

        // Nomor kamar: angka 3-4 digit yang berdiri sendiri (bukan bagian tanggal / jam), yang pertama ada di Data kamar.
        preg_match_all('~(?<![\d/.:\-])#?(\d{3,4})(?![\d/.:\-])~', $clean, $numbers);
        $candidates = array_values(array_unique($numbers[1]));
        if ($candidates === []) {
            return $result;
        }
        $rooms = Room::where('is_active', true)->whereIn('number', $candidates)->get()->keyBy('number');
        foreach ($candidates as $number) {
            if ($rooms->has($number)) {
                $result['room'] = $rooms->get($number);
                break;
            }
        }
        $result['room_number'] = $result['room']?->number ?? $candidates[0];

        // Barang tertinggal ("1203 complete tertinggal" + foto): didahulukan dari permintaan / kendala.
        if ($this->matches('lost', $clean)) {
            $result['type'] = 'lost';
            $result['description'] = $this->lostDescription($clean);
            if (! $result['room']) {
                $result['warnings'][] = __('Kamar :number tidak ada di data kamar. Periksa lagi nomornya.', ['number' => $result['room_number']]);
            }

            return $result;
        }

        // Permintaan: tiap sebutan barang (yang terpanjang dulu, supaya "bath towel" tidak terbaca sebagai "towel" + sisa).
        $labels = GuestRequest::kinds()->labels(activeOnly: true); // jenis nonaktif tidak dibaca
        $taken = preg_replace('~'.self::NEGATION.'~u', ' ', $clean);
        $found = []; // kind => ['sum' => n, 'names' => []]
        foreach ($this->group('item')->filter(fn (QuickTerm $t) => isset($labels[$t->target]))->sortByDesc(fn (QuickTerm $t) => mb_strlen($t->term)) as $term) {
            $pattern = '~'.self::QUANTITY.QuickTerm::pattern($term->term).'~u';
            if (! preg_match_all($pattern, $taken, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $found[$term->target] ??= ['sum' => 0, 'names' => []];
            foreach ($matches[0] as $index => [$match, $offset]) {
                $number = $matches[1][$index][0] ?? '';
                $found[$term->target]['sum'] += $number !== '' ? (int) $number : 0;
                $found[$term->target]['names'][$offset] = trim(preg_replace('~^'.self::QUANTITY.'~u', '', $match));
            }
            $taken = preg_replace($pattern, ' ', $taken);
        }
        foreach (array_keys($labels) as $kind) { // urutan jenis tetap seperti daftar jenis
            if (isset($found[$kind])) {
                $result['items'][] = [
                    'kind' => $kind, 'quantity' => max(1, min(20, $found[$kind]['sum'] ?: 1)), 'label' => $labels[$kind],
                    // Barang "lainnya" yang dikenal: namanya disimpan ("duvet, bed topper").
                    'name' => $kind === 'other' ? mb_substr(implode(', ', array_unique(collect($found[$kind]['names'])->sortKeys()->all())), 0, 80) : null,
                ];
            }
        }

        // Kendala: tanda kendala yang muncul paling awal di pesan menentukan kategorinya ("minibar tidak dingin" = minibar,
        // "AC tidak dingin" = AC). Frasa "abaikan" (mis. "shower room") tidak dihitung.
        $issueText = $taken;
        foreach ($this->group('ignore') as $ignore) {
            $issueText = preg_replace('~'.QuickTerm::pattern($ignore->term).'~u', ' ', $issueText);
        }
        $best = null; // [offset, -panjang, id kategori]
        $activeCategories = IssueCategory::where('is_active', true)->pluck('id')->flip();
        foreach ($this->group('issue')->filter(fn (QuickTerm $t) => $activeCategories->has((int) $t->target)) as $term) {
            if (preg_match('~'.QuickTerm::pattern($term->term).'~u', $issueText, $hit, PREG_OFFSET_CAPTURE)) {
                $candidate = [$hit[0][1], -strlen($hit[0][0]), (int) $term->target];
                if ($best === null || $candidate < $best) {
                    $best = $candidate;
                }
            }
        }
        // Pesan status kamar ("1204 Q for shower 1207", pindah kamar) bukan kendala.
        if (preg_match('~(?<![\p{L}])q(?![\p{L}])~u', $clean)) {
            $best = null;
        }
        $result['category'] = $best ? IssueCategory::where('is_active', true)->find($best[2]) : null;
        $issueWord = $result['category'] !== null;
        $result['priority'] = $this->matches('urgent', $clean) ? 'mendesak' : 'biasa';

        // Permintaan bila ada jenis yang terbaca dan (ada kata minta / tidak ada tanda kendala); selain itu kendala bila ada tandanya.
        $asksFor = $this->matches('request_word', $clean);
        // "laundry" saja sering kabar status ("laundry 3166 sudah ready?"): baru permintaan bila ada kata minta / pick up.
        if (! $asksFor && ! preg_match('~(?:pick\s*-?up|ambil|jemput)\s+(?:the\s+)?laundry|laundry\s+pick\s*-?up~u', $clean)) {
            $result['items'] = array_values(array_filter($result['items'], fn ($item) => $item['kind'] !== 'laundry'));
        }
        // Barang yang tidak dikenal ("102 req lan cable"): tetap permintaan, jenis Lainnya dengan nama dari teks sesudah kata minta.
        if ($result['items'] === [] && ! $issueWord && $asksFor && ($unknown = $this->unknownItem($taken))) {
            $result['items'][] = ['kind' => 'other', 'quantity' => $unknown['quantity'], 'label' => $labels['other'] ?? __('Lainnya'), 'name' => $unknown['name']];
        }
        if ($result['items'] !== [] && ($asksFor || ! $issueWord)) {
            $result['type'] = 'request';
            $result['category'] = null;
        } elseif ($issueWord) {
            $result['type'] = 'issue';
            $result['items'] = [];
        } else {
            return ['type' => null] + $result;
        }

        if (! $result['room']) {
            $result['warnings'][] = __('Kamar :number tidak ada di data kamar. Periksa lagi nomornya.', ['number' => $result['room_number']]);
        }

        return $result;
    }

    /** @return Collection<int, QuickTerm> */
    /**
     * Balasan singkat pada kartu tugas (App\Chat\ReplyAction): 'done' bila isinya kata kelompok `done` ("done", "sudah", "ok done"),
     * 'claim' bila kata kelompok `claim` ("copy", "ok", "otw ya *eka", "siap pak 👍"), selain itu null. Hanya kata kamus ditambah sapaan /
     * tanda baca / emoji; kalimat lain ("ok nanti saya cek dulu kamarnya") bukan.
     *
     * @return 'claim'|'done'|null
     */
    public function replyAction(string $text): ?string
    {
        $clean = mb_strtolower(trim(preg_replace('/\*\s*[\p{L}.\-]+\s*$/u', '', $text)));
        if ($clean === '' || mb_strlen($clean) > 60) {
            return null;
        }
        $done = $this->matches('done', $clean);
        if (! $done && ! $this->matches('claim', $clean)) {
            return null;
        }
        foreach ([...$this->group('done'), ...$this->group('claim')] as $term) {
            $clean = preg_replace('~'.QuickTerm::pattern($term->term).'~u', ' ', $clean);
        }
        $rest = preg_replace('~'.self::CLAIM_FILLER.'~u', ' ', $clean);
        if (preg_replace('~[^\p{L}\d]+~u', '', $rest) !== '') {
            return null;
        }

        return $done ? 'done' : 'claim';
    }

    private function group(string $group): Collection
    {
        $this->terms ??= QuickTerm::all()->groupBy('group');

        return $this->terms->get($group, collect());
    }

    private function matches(string $group, string $text): bool
    {
        return $this->group($group)->contains(fn (QuickTerm $term) => preg_match('~'.QuickTerm::pattern($term->term).'~u', $text) === 1);
    }

    /** Uraian barang tertinggal: teks tanpa nomor kamar, kata "tertinggal", dan kata kabar status kamar; null bila tidak tersisa apa pun. */
    private function lostDescription(string $text): ?string
    {
        $rest = preg_replace('~(?<![\d/.:\-])#?\d{3,4}(?![\d/.:\-])~', ' ', $text);
        foreach ($this->group('lost') as $term) {
            $rest = preg_replace('~'.QuickTerm::pattern($term->term).'~u', ' ', $rest);
        }
        $rest = preg_replace('~\b(?:complete\w*|komplit\w*|complit\w*|komplite|lengkap|done|ready|ok|room|kamar|kmr|ini|ya|hk|team|dibantu|dbntu|info|ada|di|yg|yang|ex|co|c/o|found|ditemukan)\b~u', ' ', $rest);
        $rest = trim(preg_replace('~\s+~u', ' ', trim($rest, " \t\n,.;:-")));

        return preg_match('~\p{L}{2,}~u', $rest) ? mb_substr($rest, 0, 200) : null;
    }

    /** Nama + jumlah barang sesudah kata minta pertama, atau null bila tidak ada nama yang masuk akal. @return array{name: string, quantity: int}|null */
    private function unknownItem(string $text): ?array
    {
        $words = $this->group('request_word')->map(fn (QuickTerm $term) => QuickTerm::pattern($term->term));
        if ($words->isEmpty() || ! preg_match('~(?:'.$words->implode('|').')\s*(.*)$~us', $text, $after)) {
            return null;
        }
        $rest = ' '.trim(preg_replace('~(?<![\d/.:\-])#?\d{3,4}(?![\d/.:\-])~', ' ', $after[1], 1) ?? '');
        $rest = preg_split('~'.self::NAME_STOP.'~u', $rest)[0] ?? '';
        $rest = trim(preg_replace('~\s+~u', ' ', $rest));
        $quantity = 1;
        if (preg_match('~^(\d{1,2})\s*(?:x|pcs|pc|buah)?\s+~u', $rest, $number)) {
            $quantity = max(1, min(20, (int) $number[1]));
            $rest = substr($rest, strlen($number[0]));
        }
        $rest = trim(preg_replace('~'.self::NAME_FILLER.'~u', '', $rest));
        if (! preg_match('~\p{L}{2,}~u', $rest)) {
            return null;
        }

        return ['name' => mb_substr($rest, 0, 60), 'quantity' => $quantity];
    }
}

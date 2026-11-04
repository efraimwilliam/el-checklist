<?php

namespace App\Chronology;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Membaca teks Chronology Report yang ditempel (dari WhatsApp / Word / email) menjadi data terstruktur, sehingga pegawai cukup
 * satu kali tempel. Mengenali baris "Guest Name :", "Room Number :", "Nationality :", "Length of stay :", "Source of Booking :",
 * poin bernomor ("1. ...", baris lanjutan digabung), serta penutup ("Warm regards," + nama + jabatan). Format *tebal* WhatsApp dan
 * karakter tak terlihat dibuang. Yang tidak terbaca dilaporkan lewat `warnings` (pesan Indonesia untuk pegawai).
 */
final class ReportParser
{
    private const MONTHS = [
        'januari' => 'January', 'februari' => 'February', 'maret' => 'March', 'april' => 'April', 'mei' => 'May', 'juni' => 'June', 'juli' => 'July',
        'agustus' => 'August', 'september' => 'September', 'oktober' => 'October', 'november' => 'November', 'desember' => 'December',
        'agu' => 'Aug', 'okt' => 'Oct', 'des' => 'Dec', 'sept' => 'Sep',
    ];

    /**
     * @return array{guests: list<array{name: string, room_number: string}>, nationality: ?string, check_in: ?string, check_out: ?string, booking_source: ?string, entries: list<string>, author: ?string, position: ?string, warnings: list<string>}
     */
    public static function parse(string $text): array
    {
        // Buang karakter tak terlihat (mis. word joiner di awal baris hasil salin WhatsApp) dan penanda tebal.
        $text = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00A0}]/u', ' ', $text) ?? $text;
        $lines = array_map(fn (string $line) => trim(str_replace('*', '', $line)), preg_split('/\R/u', $text) ?: []);

        $result = ['guests' => [], 'nationality' => null, 'check_in' => null, 'check_out' => null, 'booking_source' => null, 'entries' => [], 'author' => null, 'position' => null, 'warnings' => []];
        $entries = [];
        $current = null; // indeks poin yang sedang dibaca
        $phase = 'header'; // header -> body (poin) -> closing
        $closingLines = [];
        $loose = []; // paragraf tanpa nomor (cadangan)
        $paragraph = [];

        $headerLabels = '/^(?<label>guest\s*name|guest|name|room\s*number|room\s*no\.?|room|nationality|length\s*of\s*stay|date\s*of\s*stay|period|stay|source\s*of\s*booking|booking\s*source|source)\s*[:：]\s*(?<value>.*)$/iu';

        foreach ($lines as $line) {
            if ($line === '') {
                if ($paragraph) { $loose[] = implode(' ', $paragraph); $paragraph = []; }
                continue;
            }
            if (preg_match('/^chronology\s+report$/iu', $line)) {
                continue;
            }
            if (preg_match('/^(this\s+chronology\s+report\s+is\s+made|(warm|best|kind)\s+regards|regards|sincerely|thank\s+you)/iu', $line)) {
                $phase = 'closing';
                if (! preg_match('/^this\s+chronology/iu', $line)) {
                    $closingLines[] = null; // penanda: baris setelah salam = nama dan jabatan
                }
                continue;
            }
            if ($phase === 'closing') {
                if (end($closingLines) === null && $closingLines) {
                    array_pop($closingLines);
                }
                $closingLines[] = $line;
                continue;
            }

            if ($phase === 'header' && preg_match($headerLabels, $line, $m)) {
                $label = mb_strtolower(preg_replace('/\s+/', ' ', $m['label']));
                $value = trim($m['value']);
                if (str_starts_with($label, 'guest') || $label === 'name') {
                    $result['guests'][] = ['name' => $value, 'room_number' => ''];
                } elseif (str_starts_with($label, 'room')) {
                    $index = array_key_last($result['guests']);
                    if ($index !== null && $result['guests'][$index]['room_number'] === '') {
                        $result['guests'][$index]['room_number'] = ltrim($value, '#');
                    } else {
                        $result['guests'][] = ['name' => '', 'room_number' => ltrim($value, '#')];
                    }
                } elseif ($label === 'nationality') {
                    $result['nationality'] = $value ?: null;
                } elseif (in_array($label, ['length of stay', 'date of stay', 'period', 'stay'], true)) {
                    [$result['check_in'], $result['check_out']] = self::stay($value);
                } else {
                    $result['booking_source'] = $value ?: null;
                }
                continue;
            }

            if (preg_match('/^(\d{1,2})\s*[\.\)]\s+(.+)$/u', $line, $m)) {
                $phase = 'body';
                $entries[] = $m[2];
                $current = array_key_last($entries);
                $paragraph = [];
                continue;
            }

            if ($phase === 'body' && $current !== null) {
                $entries[$current] .= ' '.$line; // baris lanjutan poin yang sama
                continue;
            }
            $paragraph[] = $line;
        }
        if ($paragraph) {
            $loose[] = implode(' ', $paragraph);
        }

        // Tanpa nomor sama sekali: tiap paragraf menjadi satu poin.
        $clean = fn (string $text) => trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $result['entries'] = $entries ? array_map($clean, $entries) : array_values(array_filter(array_map($clean, $loose)));
        $result['guests'] = array_values(array_filter($result['guests'], fn (array $g) => $g['name'] !== '' || $g['room_number'] !== ''));

        $closingLines = array_values(array_filter($closingLines, fn ($l) => $l !== null));
        $result['author'] = $closingLines[0] ?? null;
        $result['position'] = $closingLines[1] ?? null;

        if (! array_filter($result['guests'], fn (array $g) => $g['name'] !== '')) {
            $result['warnings'][] = __('Nama tamu tidak terbaca. Pastikan ada baris "Guest Name : …".');
        }
        if ($result['check_in'] === null) {
            $result['warnings'][] = __('Tanggal menginap tidak terbaca. Pastikan ada baris "Length of stay : 02 November 2026 - 05 November 2026", atau isi manual.');
        }
        if (! $result['entries']) {
            $result['warnings'][] = __('Poin kronologi tidak terbaca. Awali tiap poin dengan nomor, mis. "1. …".');
        }

        return $result;
    }

    /** "02 November 2026 - 05 November 2026" -> ['2026-11-02', '2026-11-05'] (check-out boleh kosong). @return array{0: ?string, 1: ?string} */
    private static function stay(string $value): array
    {
        $parts = preg_split('/\s*(?:–|—|-|\bto\b|\bs\/d\b|\bsd\b|\buntil\b)\s*(?=\d)/iu', trim($value)) ?: [];
        $each = array_map(fn (string $part) => self::date($part), $parts);

        // Tanggal pertama hanya angka hari ("02 - 05 November 2026"): pakai bulan dan tahun dari tanggal terakhir.
        if (count($parts) === 2 && $each[0] === null && $each[1] !== null && preg_match('/^\d{1,2}$/', trim($parts[0]))) {
            $each[0] = CarbonImmutable::parse($each[1])->setDay((int) $parts[0])->toDateString();
        }
        $dates = array_values(array_filter($each));

        return [$dates[0] ?? null, $dates[1] ?? null];
    }

    private static function date(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        foreach (self::MONTHS as $id => $en) {
            $text = preg_replace('/\b'.$id.'\b/iu', $en, $text) ?? $text;
        }
        if (! preg_match('/\d/', $text)) {
            return null;
        }
        try {
            // dd/mm/yyyy dibaca hari-bulan-tahun (kebiasaan di sini), bukan format Amerika.
            if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $text, $m)) {
                return CarbonImmutable::createStrict((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            }

            return preg_match('/\d{4}/', $text) ? CarbonImmutable::parse($text)->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }
}

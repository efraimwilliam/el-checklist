<?php

namespace App\Chronology;

use App\Models\ChronologyReport;

/**
 * Teks Chronology Report dalam bahasa Inggris (tetap, bukan terjemahan antarmuka), dipakai tampilan cetak dan tombol "Salin teks"
 * untuk WhatsApp / email: judul tebal, data tamu, poin bernomor, pernyataan, "Warm regards," dan nama + jabatan penulis.
 */
final class ReportText
{
    public const TITLE = 'CHRONOLOGY REPORT';

    public const STATEMENT = 'This chronology report is made truthfully and to the best of my knowledge.';

    public const CLOSING = 'Warm regards,';

    /** "02 November 2026 - 05 November 2026", atau satu tanggal bila belum ada check-out. */
    public static function stay(ChronologyReport $report): string
    {
        $in = $report->check_in->locale('en')->translatedFormat('d F Y');

        return $report->check_out ? $in.' - '.$report->check_out->locale('en')->translatedFormat('d F Y') : $in;
    }

    /** @return list<array{label: string, value: string}> baris data di kepala laporan, berurutan seperti contoh. */
    public static function header(ChronologyReport $report): array
    {
        $rows = [];
        foreach ($report->guests as $guest) {
            $rows[] = ['label' => 'Guest Name', 'value' => $guest->name];
            if (filled($guest->room_number)) {
                $rows[] = ['label' => 'Room Number', 'value' => $guest->room_number];
            }
        }
        if (filled($report->nationality)) {
            $rows[] = ['label' => 'Nationality', 'value' => $report->nationality];
        }
        $rows[] = ['label' => 'Length of stay', 'value' => self::stay($report)];
        if (filled($report->booking_source)) {
            $rows[] = ['label' => 'Source of Booking', 'value' => $report->booking_source];
        }

        return $rows;
    }

    /** Nama dan jabatan penulis (jabatan dari data pegawai; kosong bila tidak ada). @return array{name: string, position: ?string} */
    public static function author(ChronologyReport $report): array
    {
        return [
            'name' => $report->author?->name() ?? '',
            'position' => filled($report->author?->employee?->position_title) ? $report->author->employee->position_title : null,
        ];
    }

    /** Teks siap tempel (format WhatsApp: *tebal*). */
    public static function plain(ChronologyReport $report): string
    {
        $report->loadMissing(['guests', 'entries', 'author.employee']);
        $lines = ['*'.self::TITLE.'*', ''];
        foreach (self::header($report) as $row) {
            $lines[] = $row['label'].' : '.$row['value'];
        }
        $lines[] = '';
        foreach ($report->entries as $index => $entry) {
            $lines[] = ($index + 1).'. '.trim($entry->body);
        }
        $author = self::author($report);
        $lines = array_merge($lines, ['', self::STATEMENT, '', self::CLOSING, '', '*'.$author['name']]);
        if ($author['position']) {
            $lines[] = $author['position'];
        }

        return implode("\n", $lines);
    }
}

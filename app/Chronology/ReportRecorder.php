<?php

namespace App\Chronology;

use App\Models\ChronologyReport;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Menyimpan Chronology Report (tamu + poin) dan membuat draft dari teks tempelan; dipakai form web dan bot Telegram. */
final class ReportRecorder
{
    /** Simpan laporan baru atau yang sudah ada (tamu dan poin diganti seluruhnya sesuai urutan). */
    public static function persist(User $user, ChronologyReport $report, array $data, Collection $guests, Collection $entries, ?int $issueId, bool $final = false): void
    {
        DB::transaction(function () use ($user, $report, $data, $guests, $entries, $issueId, $final) {
            $report->fill([
                'nationality' => filled($data['nationality'] ?? null) ? trim($data['nationality']) : null,
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'] ?? null,
                'booking_source' => filled($data['booking_source'] ?? null) ? trim($data['booking_source']) : null,
                'room_issue_id' => $issueId,
            ]);
            if (! $report->exists) {
                $report->fill(['author_id' => $user->id, 'department_id' => $user->checklistDepartmentId(), 'status' => ChronologyReport::STATUS_DRAFT]);
            }
            $report->save();

            $report->guests()->delete();
            foreach ($guests as $index => $guest) {
                $report->guests()->create(['sort' => $index + 1, 'name' => trim($guest['name']), 'room_number' => filled($guest['room_number'] ?? null) ? trim($guest['room_number']) : null]);
            }
            $report->entries()->delete();
            foreach ($entries as $index => $entry) {
                $report->entries()->create(['sort' => $index + 1, 'occurred_at' => filled($entry['occurred_at'] ?? null) ? $entry['occurred_at'] : null, 'body' => trim($entry['body'])]);
            }
            if ($final) {
                $report->update(['status' => ChronologyReport::STATUS_FINAL, 'finalized_at' => now()]);
            }
        });
    }

    /**
     * Baca teks tempelan dan simpan sebagai draft bila nama tamu, tanggal check-in, dan poin terbaca lengkap.
     *
     * @return array{report: ?ChronologyReport, missing: list<string>, warnings: list<string>}
     */
    public static function draftFromText(User $user, string $text): array
    {
        $parsed = ReportParser::parse($text);
        $guests = collect($parsed['guests'])->filter(fn ($g) => $g['name'] !== '')->map(fn ($g) => ['name' => mb_substr($g['name'], 0, 120), 'room_number' => mb_substr($g['room_number'], 0, 30)])->values();
        $entries = collect($parsed['entries'])->map(fn ($body) => ['body' => mb_substr($body, 0, 4000), 'occurred_at' => null])->values();
        $checkIn = $parsed['check_in'];
        $checkOut = $parsed['check_out'] && $checkIn && $parsed['check_out'] >= $checkIn ? $parsed['check_out'] : null;

        $missing = array_values(array_filter([
            $guests->isEmpty() ? __('nama tamu (Guest Name)') : null,
            $checkIn === null ? __('tanggal menginap (Length of stay)') : null,
            $entries->isEmpty() ? __('poin kejadian (1. 2. 3.)') : null,
        ]));
        if ($missing) {
            return ['report' => null, 'missing' => $missing, 'warnings' => $parsed['warnings']];
        }

        $report = new ChronologyReport;
        self::persist($user, $report, [
            'nationality' => $parsed['nationality'] ? mb_substr($parsed['nationality'], 0, 80) : null,
            'check_in' => $checkIn, 'check_out' => $checkOut,
            'booking_source' => $parsed['booking_source'] ? mb_substr($parsed['booking_source'], 0, 120) : null,
        ], $guests, $entries, null);

        return ['report' => $report, 'missing' => [], 'warnings' => $parsed['warnings']];
    }
}

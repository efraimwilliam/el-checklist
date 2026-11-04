<?php

namespace App\Chronology;

use App\Models\ChronologyReport;
use App\Models\ChronologyReportEntry;
use App\Models\ChronologyReportGuest;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Insight Chronology Report untuk dashboard: laporan yang dibuat dalam rentang dan yang boleh dilihat pengguna.
 * Angka dihitung di SQL; daftar teratas dibatasi supaya halaman tetap ringan.
 */
class ChronologyInsight
{
    private const TOP = 5;

    /** @return array<string, mixed> */
    public function build(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $reports = ChronologyReport::visibleTo($user)->whereBetween('chronology_reports.created_at', [$from->startOfDay(), $to->endOfDay()]);
        $ids = (clone $reports)->pluck('chronology_reports.id')->all();

        $total = count($ids);
        $final = (clone $reports)->where('chronology_reports.status', ChronologyReport::STATUS_FINAL)->count();
        $entries = $total ? ChronologyReportEntry::whereIn('chronology_report_id', $ids)->count() : 0;
        $guests = $total ? ChronologyReportGuest::whereIn('chronology_report_id', $ids)->get(['name', 'room_number']) : collect();

        return [
            'total' => $total,
            'final' => $final,
            'draft' => $total - $final,
            'guests' => $guests->count(),
            'uniqueGuests' => $guests->pluck('name')->map(fn ($name) => mb_strtolower(trim((string) $name)))->filter()->unique()->count(),
            'entriesAverage' => $total ? round($entries / $total, 1) : null,
            'linkedIssues' => (clone $reports)->whereNotNull('chronology_reports.room_issue_id')->count(),
            'sources' => $this->top(clone $reports, 'chronology_reports.booking_source'),
            'nationalities' => $this->top(clone $reports, 'chronology_reports.nationality'),
            'rooms' => $guests->map(fn ($g) => trim((string) $g->room_number))->filter()->countBy()->sortDesc()->take(self::TOP)
                ->map(fn ($count, $room) => ['label' => $room, 'count' => $count])->values()->all(),
            'recent' => (clone $reports)->with(['guests', 'author'])->latest('chronology_reports.id')->limit(self::TOP)->get()
                ->map(fn (ChronologyReport $report) => [
                    'id' => $report->id,
                    'guest' => $report->guests->pluck('name')->filter()->implode(', ') ?: __('Tanpa nama tamu'),
                    'room' => $report->guests->pluck('room_number')->filter()->implode(', '),
                    'status' => $report->status,
                    'author' => $report->author?->name() ?? '',
                    'date' => $report->created_at?->format('d M Y'),
                ])->all(),
        ];
    }

    /** @return list<array{label: string, count: int}> */
    private function top($query, string $column): array
    {
        return $query->whereNotNull($column)->where($column, '!=', '')
            ->groupBy($column)->selectRaw("{$column} as label, count(*) as count")
            ->orderByDesc('count')->orderBy('label')->limit(self::TOP)->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->count])->all();
    }
}

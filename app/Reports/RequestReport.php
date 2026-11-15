<?php

namespace App\Reports;

use App\Models\GuestRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Laporan > Permintaan tamu: beban kerja dan kecepatan penanganan permintaan dalam satu rentang tanggal (tanggal dicatat).
 * Hanya permintaan yang boleh dilihat pengguna (GuestRequest::visibleTo), opsional satu departemen tujuan. Dihitung di PHP
 * (rentang maks 92 hari, jumlah permintaan per hari ratusan).
 */
class RequestReport
{
    /** Diambil dalam batas ini (menit) dihitung "tepat waktu" di KPI. */
    public const CLAIM_TARGET = 15;

    /** @return array<string, mixed> */
    public function build(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $departmentId): array
    {
        $rows = GuestRequest::visibleTo($user)->with(['room', 'department', 'claimer.employee', 'completer.employee'])
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($departmentId, fn ($q, $id) => $q->where('assigned_department_id', $id))
            ->get();

        $avg = fn (Collection $values) => $values->isEmpty() ? null : (int) round($values->avg());
        $claims = fn (Collection $items) => $items->map->minutesToClaim()->filter(fn ($m) => $m !== null)->values();
        $dones = fn (Collection $items) => $items->map->minutesToDone()->filter(fn ($m) => $m !== null)->values();
        $claimAll = $claims($rows);

        $byHour = collect(range(0, 23))->mapWithKeys(fn ($hour) => [$hour => ['count' => 0, 'claim' => null]])->all();
        foreach ($rows->groupBy(fn (GuestRequest $r) => (int) $r->created_at->format('G')) as $hour => $items) {
            $byHour[$hour] = ['count' => $items->count(), 'claim' => $avg($claims($items))];
        }

        $byWeekday = collect(range(1, 7))->mapWithKeys(fn ($day) => [$day => 0])->all();
        foreach ($rows->groupBy(fn (GuestRequest $r) => (int) $r->created_at->format('N')) as $day => $items) {
            $byWeekday[$day] = $items->count();
        }

        $byKind = $rows->groupBy('kind')->map(fn (Collection $items) => [
            'label' => $items->first()->kindLabel(),
            'count' => $items->count(),
            'quantity' => (int) $items->sum('quantity'),
            'claim' => $avg($claims($items)),
            'done' => $avg($dones($items)),
            'open' => $items->where('status', '!=', GuestRequest::STATUS_DONE)->count(),
        ])->sortByDesc('count')->values()->all();

        // Petugas: yang mengambil (atau menyelesaikan langsung). Rata-rata = dari diambil sampai selesai.
        $byStaff = $rows->filter(fn (GuestRequest $r) => $r->claimed_by !== null)->groupBy('claimed_by')->map(fn (Collection $items) => [
            'name' => $items->first()->claimer?->name() ?? __('Pengguna dihapus'),
            'taken' => $items->count(),
            'done' => $items->where('status', GuestRequest::STATUS_DONE)->count(),
            'claim' => $avg($claims($items)),
            'work' => $avg($items->filter(fn ($r) => $r->claimed_at && $r->completed_at)->map(fn ($r) => (int) round($r->claimed_at->diffInSeconds($r->completed_at) / 60))->values()),
        ])->sortByDesc('taken')->values()->all();

        $byDepartment = $rows->groupBy(fn (GuestRequest $r) => $r->assigned_department_id ?? 0)->map(fn (Collection $items) => [
            'name' => $items->first()->department?->name ?? __('Belum ditugaskan'),
            'count' => $items->count(),
            'claim' => $avg($claims($items)),
            'done' => $avg($dones($items)),
        ])->sortByDesc('count')->values()->all();

        $byRoom = $rows->groupBy('room_id')->map(fn (Collection $items) => ['room' => $items->first()->room?->number ?? '—', 'count' => $items->count()])
            ->sortByDesc('count')->take(10)->values()->all();

        $slowest = $rows->filter(fn (GuestRequest $r) => $r->minutesToClaim() !== null)->sortByDesc(fn ($r) => $r->minutesToClaim())->take(10)->values();
        $waiting = $rows->where('status', GuestRequest::STATUS_NEW)->sortBy('created_at')->take(10)->values();

        return [
            'kpi' => [
                'total' => $rows->count(),
                'done' => $rows->where('status', GuestRequest::STATUS_DONE)->count(),
                'open' => $rows->where('status', '!=', GuestRequest::STATUS_DONE)->count(),
                'urgent' => $rows->where('priority', 'mendesak')->count(),
                'from_chat' => $rows->where('source', 'chat')->count(),
                'avg_claim' => $avg($claimAll),
                'avg_done' => $avg($dones($rows)),
                'on_time' => $claimAll->isEmpty() ? null : (int) round($claimAll->filter(fn ($m) => $m <= self::CLAIM_TARGET)->count() / $claimAll->count() * 100),
            ],
            'by_hour' => $byHour,
            'peak_hour' => collect($byHour)->sortByDesc('count')->keys()->first(),
            'by_weekday' => $byWeekday,
            'by_kind' => $byKind,
            'by_staff' => $byStaff,
            'by_department' => $byDepartment,
            'by_room' => $byRoom,
            'slowest' => $slowest,
            'waiting' => $waiting,
        ];
    }
}

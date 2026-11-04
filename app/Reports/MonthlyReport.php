<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/** Laporan bulanan dari `report_snapshots` (12 bulan terakhir), untuk departemen di `$ids`. */
class MonthlyReport
{
    /** @return list<array<string, mixed>> bulan terbaru dulu */
    public function months(array $ids, ?int $departmentId = null, int $months = 12): array
    {
        return DB::table('report_snapshots')->whereIn('department_id', $ids)
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->where('date', '>=', now()->startOfMonth()->subMonths($months - 1)->toDateString())
            ->groupByRaw("DATE_FORMAT(date, '%Y-%m')")->orderByRaw("DATE_FORMAT(date, '%Y-%m') desc")
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as month, count(distinct date) as days, sum(checklists) as checklists, sum(items) as items,
                sum(done) as done, sum(ongoing) as ongoing, sum(pending) as pending, sum(blank) as blank")
            ->get()->map(fn ($r) => [
                'month' => $r->month,
                'days' => (int) $r->days,
                'checklists' => (int) $r->checklists,
                'items' => (int) $r->items,
                'done' => (int) $r->done,
                'ongoing' => (int) $r->ongoing,
                'pending' => (int) $r->pending,
                'blank' => (int) $r->blank,
                'score' => $r->items ? (int) round($r->done / $r->items * 100) : null,
                'pendingRate' => $r->items ? round($r->pending / $r->items * 100, 1) : null,
            ])->all();
    }

    /**
     * Per departemen untuk satu bulan (Y-m).
     *
     * @return list<array<string, mixed>>
     */
    public function departments(array $ids, string $month): array
    {
        return DB::table('report_snapshots as s')->join('departments as d', 'd.id', '=', 's.department_id')
            ->whereIn('s.department_id', $ids)->whereRaw("DATE_FORMAT(s.date, '%Y-%m') = ?", [$month])
            ->groupBy('s.department_id', 'd.name')->orderBy('d.name')
            ->selectRaw('d.name, sum(s.checklists) as checklists, sum(s.items) as items, sum(s.done) as done, sum(s.pending) as pending, sum(s.blank) as blank')
            ->get()->map(fn ($r) => [
                'name' => $r->name,
                'checklists' => (int) $r->checklists,
                'pending' => (int) $r->pending,
                'blank' => (int) $r->blank,
                'score' => $r->items ? (int) round($r->done / $r->items * 100) : null,
            ])->all();
    }
}

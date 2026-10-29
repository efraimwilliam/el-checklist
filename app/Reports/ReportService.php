<?php

namespace App\Reports;

use App\Checklists\DashboardService;
use App\Enums\ChecklistStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Isi laporan untuk satu rentang tanggal (hanya departemen yang boleh diakses pengguna):
 * ringkasan + pembanding periode sebelumnya, per departemen, per jabatan, per staff, tugas yang sering dilewati,
 * tugas tertunda, alasan Pending, kebiasaan pengisian & review, foto yang kurang, dan pola per hari dalam seminggu.
 */
class ReportService
{
    public function __construct(private DashboardService $dashboard) {}

    /** @return array<string, mixed> */
    public function build(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = $this->dashboard->build($user, $from, $to);
        $ids = $this->departmentIds($user);
        $departments = Department::whereIn('id', $ids)->orderBy('sort')->orderBy('name')->get();
        $names = $departments->pluck('name', 'id');
        $range = [$from->toDateString(), $to->toDateString()];

        $checklists = Checklist::with('items.photos')->whereIn('department_id', $ids)->whereBetween('date', $range)->get();
        $employees = Employee::where('is_active', true)->whereIn('department_id', $ids)->orderBy('name')->get(['id', 'name', 'department_id']);
        $checkedEmployees = $checklists->pluck('employee_id')->unique()->all();

        $rows = $departments->map(function (Department $department) use ($checklists, $employees, $checkedEmployees) {
            $mine = $checklists->where('department_id', $department->id);
            $complete = $mine->filter(fn (Checklist $c) => $c->isComplete())->count();
            $missing = $employees->where('department_id', $department->id)->reject(fn (Employee $e) => in_array($e->id, $checkedEmployees, true))->values();

            return [
                'name' => $department->name,
                'checklists' => $mine->count(),
                'complete' => $complete,
                'completion' => $mine->count() ? (int) round($complete / $mine->count() * 100) : null,
                'pending' => $mine->sum(fn (Checklist $c) => $c->items->where('status', \App\Enums\ItemStatus::Pending)->count()),
                'unreviewed' => $mine->where('status', ChecklistStatus::Saved)->count(),
                'photos' => $mine->sum(fn (Checklist $c) => $c->missingPhotos()),
                'missing' => $missing->pluck('name')->all(),
            ];
        })->values()->all();

        $totalChecklists = $checklists->count();
        $completeTotal = $checklists->filter(fn (Checklist $c) => $c->isComplete())->count();
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        return array_merge($base, [
            'rows' => $rows,
            'kpi' => array_merge($base['kpi'], [
                'complete' => $completeTotal,
                'completion' => $totalChecklists ? (int) round($completeTotal / $totalChecklists * 100) : null,
                'missing' => collect($rows)->sum(fn (array $row) => count($row['missing'])),
                'backlog' => $this->backlog($ids),
                'photos' => collect($rows)->sum('photos'),
            ]),
            'previous' => ['from' => $previousFrom, 'to' => $previousTo, 'kpi' => $this->dashboard->build($user, $previousFrom, $previousTo)['kpi']],
            'reasons' => $this->pendingReasons($ids, $range),
            'positions' => $this->positionScores($ids, $range, $names),
            'staff' => $this->staffScores($ids, $range),
            'skipped' => $this->skippedTasks($ids, $range),
            'habits' => $this->habits($checklists),
            'weekdays' => $this->weekdays($ids, $range),
        ]);
    }

    /** Departemen yang boleh diakses pengguna (null = semua). */
    public function departmentIds(User $user): array
    {
        $allowed = $user->accessibleDepartmentIds();

        return Department::where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->pluck('id')->all();
    }

    /** Checklist lengkap yang belum direview lebih dari 2 hari (sebelum rentang juga dihitung). */
    private function backlog(array $ids): int
    {
        return Checklist::with('items')->whereIn('department_id', $ids)->where('status', ChecklistStatus::Saved)
            ->whereDate('date', '<', CarbonImmutable::today()->subDays(2))->get()
            ->filter(fn (Checklist $c) => $c->isComplete())->count();
    }

    /** Alasan Pending terbaru (catatan yang diisi di tugas Pending). */
    private function pendingReasons(array $ids, array $range): array
    {
        return ChecklistItem::with('checklist.employee', 'checklist.department')
            ->whereHas('checklist', fn ($q) => $q->whereIn('department_id', $ids)->whereBetween('date', $range))
            ->where('status', \App\Enums\ItemStatus::Pending)->whereNotNull('note')->where('note', '!=', '')
            ->orderByDesc('id')->limit(30)->get()
            ->map(fn (ChecklistItem $item) => [
                'title' => $item->title,
                'note' => $item->note,
                'employee' => $item->checklist->employee->name,
                'department' => $item->checklist->department->name,
                'date' => $item->checklist->date,
            ])->all();
    }

    /** Skor per jabatan, terendah dulu. */
    private function positionScores(array $ids, array $range, $names): array
    {
        return $this->itemsQuery($ids, $range)->groupBy('c.department_id', 'c.position_name')
            ->selectRaw("c.department_id, c.position_name, count(distinct c.id) as checklists, count(i.id) as items, coalesce(sum(i.status = 'done'), 0) as done, coalesce(sum(i.status = 'pending'), 0) as pending")
            ->get()
            ->map(fn ($r) => [
                'department' => $names[$r->department_id] ?? '',
                'position' => $r->position_name,
                'checklists' => (int) $r->checklists,
                'score' => $r->items ? (int) round($r->done / $r->items * 100) : null,
                'pending' => (int) $r->pending,
            ])->sortBy(fn (array $row) => $row['score'] ?? 101)->values()->all();
    }

    /** Skor per staff: 10 terendah (minimal satu checklist), beserta jumlah staff yang dinilai. */
    private function staffScores(array $ids, array $range): array
    {
        $rows = $this->itemsQuery($ids, $range)->groupBy('c.employee_id')
            ->selectRaw("c.employee_id, count(distinct c.id) as checklists, count(i.id) as items, coalesce(sum(i.status = 'done'), 0) as done, coalesce(sum(i.status = 'pending'), 0) as pending")
            ->get();
        $names = Employee::whereIn('id', $rows->pluck('employee_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->employee_id] ?? '',
            'checklists' => (int) $r->checklists,
            'score' => $r->items ? (int) round($r->done / $r->items * 100) : null,
            'pending' => (int) $r->pending,
        ])->sortBy(fn (array $row) => $row['score'] ?? 101)->take(10)->values()->all();
    }

    /** Tugas yang berulang kali Pending atau dibiarkan kosong untuk satu staff (minimal dua kali). */
    private function skippedTasks(array $ids, array $range): array
    {
        $rows = $this->itemsQuery($ids, $range)
            ->where(fn ($q) => $q->whereNull('i.status')->orWhere('i.status', 'pending'))
            ->groupBy('c.employee_id', 'i.title')
            ->selectRaw('c.employee_id, i.title, count(*) as times, sum(i.status is null) as blank')
            ->having('times', '>=', 2)->orderByDesc('times')->limit(15)->get();
        $names = Employee::whereIn('id', $rows->pluck('employee_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'employee' => $names[$r->employee_id] ?? '',
            'title' => $r->title,
            'times' => (int) $r->times,
            'blank' => (int) $r->blank,
        ])->all();
    }

    /** Kebiasaan pengisian: diisi di hari yang sama atau belakangan, dan rata-rata hari sampai direview. */
    private function habits($checklists): array
    {
        $total = $checklists->count();
        $sameDay = $checklists->filter(fn (Checklist $c) => $c->created_at->toDateString() === $c->date->toDateString())->count();
        $reviewed = $checklists->filter(fn (Checklist $c) => $c->reviewed_at !== null);
        $waits = $reviewed->map(fn (Checklist $c) => (int) $c->date->diffInDays($c->reviewed_at->startOfDay()));

        return [
            'total' => $total,
            'sameDay' => $sameDay,
            'later' => $total - $sameDay,
            'sameDayRate' => $total ? (int) round($sameDay / $total * 100) : null,
            'reviewed' => $reviewed->count(),
            'reviewAvgDays' => $waits->count() ? round($waits->avg(), 1) : null,
            'reviewMaxDays' => $waits->count() ? $waits->max() : null,
        ];
    }

    /** Pola per hari dalam seminggu: jumlah item dan persentase Pending. */
    private function weekdays(array $ids, array $range): array
    {
        $rows = $this->itemsQuery($ids, $range)->groupByRaw('DAYOFWEEK(c.date)')
            ->selectRaw("DAYOFWEEK(c.date) as d, count(i.id) as items, coalesce(sum(i.status = 'pending'), 0) as pending")
            ->get()->keyBy('d');
        $labels = [2 => 'Senin', 3 => 'Selasa', 4 => 'Rabu', 5 => 'Kamis', 6 => 'Jumat', 7 => 'Sabtu', 1 => 'Minggu'];

        return collect([2, 3, 4, 5, 6, 7, 1])->map(function (int $day) use ($rows, $labels) {
            $row = $rows->get($day);
            $items = (int) ($row->items ?? 0);

            return ['label' => $labels[$day], 'items' => $items, 'pending' => (int) ($row->pending ?? 0), 'rate' => $items ? (int) round(($row->pending ?? 0) / $items * 100) : null];
        })->all();
    }

    private function itemsQuery(array $ids, array $range)
    {
        return DB::table('checklist_items as i')->join('checklists as c', 'c.id', '=', 'i.checklist_id')
            ->whereIn('c.department_id', $ids)->whereBetween('c.date', $range);
    }
}

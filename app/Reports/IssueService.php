<?php

namespace App\Reports;

use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Laporan masalah per TUGAS (lintas staff dan hari) untuk satu rentang tanggal: peringkat tugas bermasalah, detail satu tugas,
 * Pending berulang, peta panas departemen x hari, dan daftar alasan Pending. Hanya departemen di `$ids`.
 * Tugas dikenali dari departemen + nama jabatan + judul (disalin ke checklist saat dibuat, jadi riwayat tetap utuh).
 */
class IssueService
{
    /** Pending berurutan (hari) atau jumlah Pending pada tugas dan staff yang sama sebelum dianggap berulang. */
    public const CHRONIC_STREAK = 3;

    public const CHRONIC_COUNT = 4;

    /**
     * Tugas dengan Pending / Ongoing / kosong, paling bermasalah dulu.
     *
     * @return list<array<string, mixed>>
     */
    public function ranked(array $ids, array $range, ?int $departmentId = null, int $limit = 50, ?string $search = null): array
    {
        $rows = $this->items($ids, $range, $departmentId)
            ->when($this->like($search), fn ($q, $like) => $q->where(fn ($w) => $w->where('i.title', 'like', $like)->orWhere('c.position_name', 'like', $like)))
            // Satu daftar per departemen: tugas yang sama dari jabatan berbeda digabung jadi satu baris.
            ->groupBy('c.department_id', 'i.title')
            ->selectRaw("c.department_id, group_concat(distinct c.position_name order by c.position_name separator ', ') as positions, i.title, count(i.id) as total, count(distinct c.employee_id) as staff,
                coalesce(sum(i.status = 'done'), 0) as done, coalesce(sum(i.status = 'ongoing'), 0) as ongoing,
                coalesce(sum(i.status = 'pending'), 0) as pending, coalesce(sum(i.status is null), 0) as blank,
                count(distinct case when i.status = 'pending' then c.employee_id end) as pending_staff")
            ->havingRaw('pending + ongoing + blank > 0')
            ->orderByDesc('pending')->orderByRaw('(ongoing + blank) desc')->orderBy('i.title')
            ->limit($limit)->get();
        $names = Department::whereIn('id', $rows->pluck('department_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'department_id' => (int) $r->department_id,
            'department' => $names[$r->department_id] ?? '',
            'positions' => $r->positions,
            'title' => $r->title,
            'total' => (int) $r->total,
            'staff' => (int) $r->staff,
            'done' => (int) $r->done,
            'ongoing' => (int) $r->ongoing,
            'pending' => (int) $r->pending,
            'blank' => (int) $r->blank,
            'pendingStaff' => (int) $r->pending_staff,
            'problemRate' => (int) round(($r->pending + $r->ongoing + $r->blank) / max(1, $r->total) * 100),
        ])->all();
    }

    /**
     * Satu tugas: kejadian per hari, per staff, dan setiap isian yang tidak Done (terbaru dulu, maksimal 200).
     *
     * @return array{trend: list<array<string, mixed>>, staff: list<array<string, mixed>>, events: list<array<string, mixed>>, total: int}
     */
    public function detail(array $ids, array $range, int $departmentId, string $title): array
    {
        $base = fn () => $this->items($ids, $range, $departmentId)->where('i.title', $title);

        $trend = $base()->groupBy('c.date')->orderBy('c.date')
            ->selectRaw("c.date, count(i.id) as total, coalesce(sum(i.status = 'done'), 0) as done, coalesce(sum(i.status = 'pending'), 0) as pending, coalesce(sum(i.status = 'ongoing'), 0) as ongoing, coalesce(sum(i.status is null), 0) as blank")
            ->get()->map(fn ($r) => ['date' => $r->date, 'total' => (int) $r->total, 'done' => (int) $r->done, 'pending' => (int) $r->pending, 'ongoing' => (int) $r->ongoing, 'blank' => (int) $r->blank])->all();

        $staff = $base()->join('employees as e', 'e.id', '=', 'c.employee_id')->groupBy('c.employee_id', 'e.name')
            ->selectRaw("e.name, count(i.id) as total, coalesce(sum(i.status = 'pending'), 0) as pending, coalesce(sum(i.status = 'ongoing'), 0) as ongoing, coalesce(sum(i.status is null), 0) as blank")
            ->havingRaw('pending + ongoing + blank > 0')->orderByDesc('pending')->orderBy('e.name')->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => (int) $r->total, 'pending' => (int) $r->pending, 'ongoing' => (int) $r->ongoing, 'blank' => (int) $r->blank])->all();

        $events = $base()->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->where(fn ($q) => $q->whereNull('i.status')->orWhere('i.status', '!=', 'done'))
            ->orderByDesc('c.date')->orderByDesc('i.id')->limit(200)
            ->get(['c.id as checklist_id', 'c.date', 'e.name as employee', 'i.status', 'i.note'])
            ->map(fn ($r) => ['checklist_id' => (int) $r->checklist_id, 'date' => $r->date, 'employee' => $r->employee, 'status' => $r->status, 'note' => $r->note])->all();

        return ['trend' => $trend, 'staff' => $staff, 'events' => $events, 'total' => array_sum(array_column($trend, 'total'))];
    }

    /**
     * Pending berulang untuk tugas dan staff yang sama: >= CHRONIC_STREAK hari berturut-turut atau >= CHRONIC_COUNT kali.
     *
     * @return list<array<string, mixed>>
     */
    public function chronic(array $ids, array $range, ?int $departmentId = null, ?string $search = null): array
    {
        $rows = $this->items($ids, $range, $departmentId)->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->when($this->like($search), fn ($q, $like) => $q->where(fn ($w) => $w->where('i.title', 'like', $like)->orWhere('e.name', 'like', $like)))
            ->where('i.status', 'pending')
            ->orderBy('c.date')
            ->get(['c.employee_id', 'e.name as employee', 'c.department_id', 'c.position_name', 'i.title', 'c.date']);
        $names = Department::whereIn('id', $rows->pluck('department_id'))->pluck('name', 'id');

        return $rows->groupBy(fn ($r) => $r->employee_id.'|'.$r->department_id.'|'.$r->position_name.'|'.$r->title)
            ->map(function ($group) use ($names) {
                $dates = $group->pluck('date')->unique()->sort()->values();
                $run = $best = 1;
                for ($i = 1; $i < $dates->count(); $i++) {
                    $run = CarbonImmutable::parse($dates[$i - 1])->addDay()->toDateString() === CarbonImmutable::parse($dates[$i])->toDateString() ? $run + 1 : 1;
                    $best = max($best, $run);
                }
                $first = $group->first();

                return [
                    'employee' => $first->employee,
                    'department' => $names[$first->department_id] ?? '',
                    'position' => $first->position_name,
                    'title' => $first->title,
                    'times' => $dates->count(),
                    'streak' => $dates->isEmpty() ? 0 : $best,
                    'last' => $dates->last(),
                ];
            })
            ->filter(fn (array $row) => $row['streak'] >= self::CHRONIC_STREAK || $row['times'] >= self::CHRONIC_COUNT)
            ->sortByDesc(fn (array $row) => [$row['streak'], $row['times']])->values()->take(50)->all();
    }

    /**
     * Peta panas: persentase Pending per departemen untuk tiap hari dalam seminggu (Senin..Minggu).
     *
     * @return array{labels: list<string>, rows: list<array{name: string, cells: list<array{items: int, pending: int, rate: ?int}>}>}
     */
    public function heatmap(array $ids, array $range): array
    {
        $data = $this->items($ids, $range, null)->groupByRaw('c.department_id, DAYOFWEEK(c.date)')
            ->selectRaw("c.department_id, DAYOFWEEK(c.date) as d, count(i.id) as items, coalesce(sum(i.status = 'pending'), 0) as pending")->get()
            ->groupBy('department_id');
        $departments = Department::whereIn('id', $ids)->orderBy('sort')->orderBy('name')->get();
        $order = [2, 3, 4, 5, 6, 7, 1];

        return [
            'labels' => [__('Sen'), __('Sel'), __('Rab'), __('Kam'), __('Jum'), __('Sab'), __('Min')],
            'rows' => $departments->filter(fn (Department $d) => $data->has($d->id))->map(function (Department $d) use ($data, $order) {
                $byDay = $data[$d->id]->keyBy('d');

                return ['name' => $d->name, 'cells' => array_map(function (int $day) use ($byDay) {
                    $row = $byDay->get($day);
                    $items = (int) ($row->items ?? 0);
                    $pending = (int) ($row->pending ?? 0);

                    return ['items' => $items, 'pending' => $pending, 'rate' => $items ? (int) round($pending / $items * 100) : null];
                }, $order)];
            })->values()->all(),
        ];
    }

    /**
     * Semua alasan Pending (catatan di tugas Pending), bisa dicari, terbaru dulu.
     *
     * @return list<array<string, mixed>>
     */
    public function reasons(array $ids, array $range, ?int $departmentId = null, ?string $search = null, int $limit = 100): array
    {
        return $this->items($ids, $range, $departmentId)->join('employees as e', 'e.id', '=', 'c.employee_id')->join('departments as d', 'd.id', '=', 'c.department_id')
            ->where('i.status', 'pending')->whereNotNull('i.note')->where('i.note', '!=', '')
            ->when($this->like($search), fn ($q, $like) => $q->where(fn ($w) => $w->where('i.note', 'like', $like)->orWhere('i.title', 'like', $like)->orWhere('e.name', 'like', $like)))
            ->orderByDesc('c.date')->orderByDesc('i.id')->limit($limit)
            ->get(['c.id as checklist_id', 'c.date', 'e.name as employee', 'd.name as department', 'c.position_name as position', 'i.title', 'i.note'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Isian Pending beserta tindak lanjutnya, tertua dulu. Pending yang tugas sama (staff sama) kemudian berstatus Done
     * dianggap selesai otomatis. `$includeResolved` = false menyembunyikan yang selesai (manual maupun otomatis).
     *
     * @return list<array<string, mixed>>
     */
    public function open(array $ids, array $range, ?int $departmentId = null, bool $includeResolved = false, int $limit = 100, ?string $search = null): array
    {
        $today = CarbonImmutable::today();
        $rows = $this->items($ids, $range, $departmentId)
            ->join('employees as e', 'e.id', '=', 'c.employee_id')->join('departments as d', 'd.id', '=', 'c.department_id')
            ->leftJoin('issue_followups as f', 'f.checklist_item_id', '=', 'i.id')
            ->where('i.status', 'pending')
            ->when($this->like($search), fn ($q, $like) => $q->where(fn ($w) => $w->where('i.title', 'like', $like)->orWhere('i.note', 'like', $like)->orWhere('e.name', 'like', $like)))
            ->selectRaw("i.id as item_id, c.id as checklist_id, c.date, c.department_id, e.name as employee, d.name as department, c.position_name as position, i.title, i.note,
                f.status as followup, f.note as followup_note,
                exists (select 1 from checklist_items i2 join checklists c2 on c2.id = i2.checklist_id where c2.employee_id = c.employee_id and i2.title = i.title and c2.date > c.date and i2.status = 'done') as auto_done")
            ->when(! $includeResolved, fn ($q) => $q->whereRaw("coalesce(f.status, 'open') <> 'resolved'")->whereRaw("not exists (select 1 from checklist_items i3 join checklists c3 on c3.id = i3.checklist_id where c3.employee_id = c.employee_id and i3.title = i.title and c3.date > c.date and i3.status = 'done')"))
            ->orderBy('c.date')->orderBy('i.id')->limit($limit)->get();

        return $rows->map(fn ($r) => [
            'item_id' => (int) $r->item_id,
            'checklist_id' => (int) $r->checklist_id,
            'department_id' => (int) $r->department_id,
            'date' => $r->date,
            'age' => (int) CarbonImmutable::parse($r->date)->diffInDays($today),
            'employee' => $r->employee,
            'department' => $r->department,
            'position' => $r->position,
            'title' => $r->title,
            'reason' => $r->note,
            'status' => $r->followup ?? 'open',
            'note' => $r->followup_note,
            'auto' => (bool) $r->auto_done && $r->followup !== 'resolved',
        ])->all();
    }

    /** Pola LIKE untuk kata yang dicari (karakter khusus di-escape), atau null bila kosong. */
    private function like(?string $search): ?string
    {
        $search = trim((string) $search);

        return $search === '' ? null : '%'.addcslashes($search, '%_\\').'%';
    }

    private function items(array $ids, array $range, ?int $departmentId)
    {
        return DB::table('checklist_items as i')->join('checklists as c', 'c.id', '=', 'i.checklist_id')
            ->whereIn('c.department_id', $ids)->whereBetween('c.date', $range)
            ->when($departmentId !== null, fn ($q) => $q->where('c.department_id', $departmentId));
    }
}

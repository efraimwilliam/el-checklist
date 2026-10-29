<?php

namespace App\Checklists;

use App\Enums\ChecklistStatus;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Angka untuk dashboard HOD / GM / HR / Administrator: status isi per departemen pada satu tanggal, serta tren, sebaran status,
 * tugas yang sering tertunda dan status review dalam satu periode. Hanya departemen yang boleh diakses pengguna.
 * Skor = persentase tugas Done dari semua tugas checklist (sama dengan skor di halaman checklist).
 */
class DashboardService
{
    /**
     * @return array{
     *     from: CarbonImmutable, to: CarbonImmutable, days: int,
     *     departments: list<array<string, mixed>>, kpi: array<string, mixed>, trend: list<array<string, mixed>>,
     *     deptScores: list<array<string, mixed>>, topPending: list<array<string, mixed>>, distribution: array<string, int>, reviews: array<string, int>
     * }
     */
    public function build(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $allowed = $user->accessibleDepartmentIds();
        $departments = Department::where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort')->orderBy('name')->get();
        $ids = $departments->pluck('id')->all();

        return [
            'from' => $from,
            'to' => $to,
            'days' => (int) $from->diffInDays($to) + 1,
            'departments' => $this->departmentCards($departments, $from, $to),
            'kpi' => $this->kpi($ids, $from, $to),
            'trend' => $this->trend($ids, $from, $to),
            'deptScores' => $this->departmentScores($departments, $ids, $from, $to),
            'topPending' => $this->topPending($departments, $ids, $from, $to),
            'distribution' => $this->distribution($ids, $from, $to),
            'reviews' => $this->reviews($ids, $from, $to),
        ];
    }

    /** Kartu "Status departemen" untuk seluruh rentang (sama dengan ringkasan dan grafik): jabatan yang sudah dicek, skor, checklist, pending. */
    private function departmentCards($departments, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $departments->pluck('id')->all();
        $positions = Position::where('is_active', true)->whereIn('department_id', $ids)->whereHas('tasks', fn ($q) => $q->where('is_active', true))
            ->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id');

        $day = $this->items($ids)->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])->groupBy('c.department_id')
            ->selectRaw($this->sums().', c.department_id, count(distinct c.id) as checklists, count(distinct case when i.status is not null then c.position_id end) as positions_checked')
            ->get()->keyBy('department_id');

        $last = $this->items($ids)->whereDate('c.date', '<=', $to)->whereNotNull('i.status')->groupBy('c.department_id')
            ->selectRaw('c.department_id, max(c.date) as last_date')->pluck('last_date', 'department_id');

        return $departments->map(function (Department $department) use ($positions, $day, $last) {
            $row = $day->get($department->id);
            $total = (int) ($positions[$department->id] ?? 0);
            $checked = (int) ($row->positions_checked ?? 0);

            return [
                'id' => $department->id,
                'name' => $department->name,
                'positions_total' => $total,
                'positions_checked' => $checked,
                'state' => $checked === 0 ? 'none' : ($total > 0 && $checked >= $total ? 'full' : 'partial'),
                'score' => $this->percent($row->done ?? 0, $row->items ?? 0),
                'checklists' => (int) ($row->checklists ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'last_date' => ($lastDate = $last[$department->id] ?? null) ? CarbonImmutable::parse($lastDate) : null,
            ];
        })->all();
    }

    private function kpi(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->items($ids)->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw($this->sums().', count(distinct c.id) as checklists')->first();
        $unreviewed = DB::table('checklists as c')->whereIn('c.department_id', $ids)->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])
            ->where('c.status', ChecklistStatus::Saved->value)->count();

        return [
            'score' => $this->percent($row->done ?? 0, $row->items ?? 0),
            'checklists' => (int) ($row->checklists ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'ongoing' => (int) ($row->ongoing ?? 0),
            'unreviewed' => $unreviewed,
        ];
    }

    /** Skor harian (semua departemen yang boleh diakses), hari tanpa checklist = null. */
    private function trend(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;

        $byDay = $this->items($ids)->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])->groupBy('c.date')
            ->selectRaw($this->sums().', c.date, count(distinct c.id) as checklists')->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->date)->toDateString());

        return collect(range(0, $days - 1))->map(function (int $offset) use ($from, $byDay) {
            $day = $from->addDays($offset);
            $row = $byDay->get($day->toDateString());

            return ['date' => $day, 'score' => $this->percent($row->done ?? 0, $row->items ?? 0), 'checklists' => (int) ($row->checklists ?? 0), 'pending' => (int) ($row->pending ?? 0)];
        })->all();
    }

    private function departmentScores($departments, array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->items($ids)->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])->groupBy('c.department_id')
            ->selectRaw($this->sums().', c.department_id, count(distinct c.id) as checklists')->get()->keyBy('department_id');

        return $departments->filter(fn (Department $d) => in_array($d->id, $ids, true))->map(fn (Department $d) => [
            'name' => $d->name,
            'score' => $this->percent($rows->get($d->id)->done ?? 0, $rows->get($d->id)->items ?? 0),
            'checklists' => (int) ($rows->get($d->id)->checklists ?? 0),
            'pending' => (int) ($rows->get($d->id)->pending ?? 0),
        ])->values()->all();
    }

    /** Tugas yang paling sering Pending (lalu Ongoing) dalam periode. */
    private function topPending($departments, array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $names = $departments->pluck('name', 'id');

        return $this->items($ids)->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])->whereIn('i.status', ['pending', 'ongoing'])
            ->groupBy('c.department_id', 'i.title')
            ->selectRaw("c.department_id, i.title, sum(i.status = 'pending') as pending, sum(i.status = 'ongoing') as ongoing")
            ->havingRaw("sum(i.status = 'pending') > 0")->orderByDesc('pending')->orderByDesc('ongoing')->limit(8)->get()
            ->map(fn ($row) => ['title' => $row->title, 'department' => $names[$row->department_id] ?? '', 'pending' => (int) $row->pending, 'ongoing' => (int) $row->ongoing])->all();
    }

    private function distribution(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->items($ids)->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])->selectRaw($this->sums())->first();

        return [
            'done' => (int) ($row->done ?? 0),
            'ongoing' => (int) ($row->ongoing ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'blank' => max(0, (int) ($row->items ?? 0) - (int) ($row->answered ?? 0)),
        ];
    }

    private function reviews(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = DB::table('checklists as c')->whereIn('c.department_id', $ids)->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('c.status, count(*) as total')->groupBy('c.status')->pluck('total', 'status');

        return collect(ChecklistStatus::cases())->mapWithKeys(fn (ChecklistStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])->all();
    }

    private function items(array $departmentIds)
    {
        return DB::table('checklist_items as i')->join('checklists as c', 'c.id', '=', 'i.checklist_id')->whereIn('c.department_id', $departmentIds);
    }

    private function sums(): string
    {
        return "count(i.id) as items, coalesce(sum(i.status = 'done'), 0) as done, coalesce(sum(i.status = 'ongoing'), 0) as ongoing, coalesce(sum(i.status = 'pending'), 0) as pending, coalesce(sum(i.status is not null), 0) as answered";
    }

    private function percent(int|string|float $part, int|string|float $total): ?int
    {
        return (int) $total > 0 ? (int) round((int) $part / (int) $total * 100) : null;
    }
}

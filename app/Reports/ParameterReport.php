<?php

namespace App\Reports;

use App\Checklists\ParameterRules;
use Illuminate\Support\Facades\DB;

/**
 * Laporan nilai parameter (angka dan stand meter): ringkasan per parameter, nilai di luar batas min / max,
 * pilihan yang bukan nilai normal, dan konsumsi stand meter (selisih nilai terakhir dan pertama dalam rentang).
 */
class ParameterReport
{
    private const NUMERIC_VALUE = "replace(p.value, ',', '.')";

    /** @return list<array<string, mixed>> */
    public function summary(array $ids, array $range, ?int $departmentId = null): array
    {
        $num = self::NUMERIC_VALUE;
        $rows = $this->base($ids, $range, $departmentId)->whereIn('p.type', ParameterRules::NUMERIC)
            ->whereRaw("{$num} regexp '^-?[0-9]+([.][0-9]+)?$'")
            // Satu baris per departemen + tugas + parameter: tugas yang sama dari jabatan berbeda digabung.
            ->groupBy('c.department_id', 'd.name', 'i.title', 'p.name', 'p.type', 'p.unit')
            ->orderBy('d.name')->orderBy('i.title')->orderBy('p.name')
            ->selectRaw("d.name as department, group_concat(distinct c.position_name order by c.position_name separator ', ') as positions, i.title, p.name as parameter, p.type, p.unit, count(*) as readings,
                min(cast({$num} as decimal(14,2))) as min_value, max(cast({$num} as decimal(14,2))) as max_value, avg(cast({$num} as decimal(14,2))) as avg_value,
                sum(case when (p.min_value is not null and cast({$num} as decimal(14,2)) < p.min_value) or (p.max_value is not null and cast({$num} as decimal(14,2)) > p.max_value) then 1 else 0 end) as out_of_range,
                min(c.date) as first_date, max(c.date) as last_date, max(p.min_value) as limit_min, max(p.max_value) as limit_max,
                substring_index(group_concat({$num} order by c.date asc, p.id asc separator '|'), '|', 1) as first_val,
                substring_index(group_concat({$num} order by c.date desc, p.id desc separator '|'), '|', 1) as last_val")
            ->get();

        $history = $this->history($ids, $range, $departmentId);

        return $rows->map(fn ($r) => [
            'history' => $history[$this->key($r->department, $r->title, $r->parameter)] ?? [],
            'department' => $r->department,
            'positions' => $r->positions,
            'title' => $r->title,
            'parameter' => $r->parameter,
            'meter' => $r->type === ParameterRules::METER,
            'unit' => $r->unit,
            'readings' => (int) $r->readings,
            'min' => (float) $r->min_value,
            'max' => (float) $r->max_value,
            'avg' => round((float) $r->avg_value, 2),
            'outOfRange' => (int) $r->out_of_range,
            'limitMin' => $r->limit_min !== null ? (float) $r->limit_min : null,
            'limitMax' => $r->limit_max !== null ? (float) $r->limit_max : null,
            'usage' => $r->type === ParameterRules::METER && $r->readings > 1 ? round((float) $r->last_val - (float) $r->first_val, 2) : null,
        ])->all();
    }

    /**
     * Track record pembacaan angka / stand meter per parameter: 10 terakhir dalam rentang, terbaru dulu.
     *
     * @return array<string, list<array{checklist_id: int, date: string, employee: string, value: float, out: bool}>>
     */
    private function history(array $ids, array $range, ?int $departmentId): array
    {
        $num = self::NUMERIC_VALUE;
        $rows = $this->base($ids, $range, $departmentId)->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->whereIn('p.type', ParameterRules::NUMERIC)->whereRaw("{$num} regexp '^-?[0-9]+([.][0-9]+)?$'")
            ->orderByDesc('c.date')->orderByDesc('p.id')->limit(3000)
            ->selectRaw("c.id as checklist_id, c.date, e.name as employee, d.name as department, c.position_name as position, i.title, p.name as parameter,
                cast({$num} as decimal(14,2)) as value, p.min_value, p.max_value")
            ->get();

        $history = [];
        foreach ($rows as $r) {
            $key = $this->key($r->department, $r->title, $r->parameter);
            if (count($history[$key] ?? []) >= 10) {
                continue;
            }
            $value = (float) $r->value;
            $history[$key][] = [
                'checklist_id' => (int) $r->checklist_id,
                'date' => $r->date,
                'employee' => $r->employee,
                'value' => $value,
                'out' => ($r->min_value !== null && $value < (float) $r->min_value) || ($r->max_value !== null && $value > (float) $r->max_value),
            ];
        }

        return $history;
    }

    private function key(string $department, string $title, string $parameter): string
    {
        return implode('|', [$department, $title, $parameter]);
    }

    /**
     * Pembacaan di luar batas atau bukan pilihan normal, terbaru dulu.
     *
     * @return list<array<string, mixed>>
     */
    public function exceptions(array $ids, array $range, ?int $departmentId = null, int $limit = 100): array
    {
        $num = self::NUMERIC_VALUE;
        $numeric = "p.type in ('number','meter') and {$num} regexp '^-?[0-9]+([.][0-9]+)?$' and ((p.min_value is not null and cast({$num} as decimal(14,2)) < p.min_value) or (p.max_value is not null and cast({$num} as decimal(14,2)) > p.max_value))";
        $option = "p.type = 'option' and p.normal_option is not null and p.normal_option <> '' and p.value is not null and p.value <> '' and p.value <> p.normal_option";

        return $this->base($ids, $range, $departmentId)->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->whereRaw("(({$numeric}) or ({$option}))")
            ->orderByDesc('c.date')->orderByDesc('p.id')->limit($limit)
            ->get(['c.id as checklist_id', 'c.date', 'd.name as department', 'e.name as employee', 'i.title', 'p.name as parameter', 'p.type', 'p.value', 'p.unit', 'p.min_value', 'p.max_value', 'p.normal_option'])
            ->map(fn ($r) => [
                'checklist_id' => (int) $r->checklist_id,
                'date' => $r->date,
                'department' => $r->department,
                'employee' => $r->employee,
                'title' => $r->title,
                'parameter' => $r->parameter,
                'value' => $r->value.($r->unit ? ' '.$r->unit : ''),
                'expected' => $r->type === 'option'
                    ? __('Normal').': '.$r->normal_option
                    : trim(($r->min_value !== null ? 'min '.(float) $r->min_value : '').' '.($r->max_value !== null ? 'maks '.(float) $r->max_value : '')),
            ])->all();
    }

    private function base(array $ids, array $range, ?int $departmentId)
    {
        return DB::table('checklist_item_parameters as p')->join('checklist_items as i', 'i.id', '=', 'p.checklist_item_id')
            ->join('checklists as c', 'c.id', '=', 'i.checklist_id')->join('departments as d', 'd.id', '=', 'c.department_id')
            ->whereIn('c.department_id', $ids)->whereBetween('c.date', $range)
            ->when($departmentId !== null, fn ($q) => $q->where('c.department_id', $departmentId));
    }
}

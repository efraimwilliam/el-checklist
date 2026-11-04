<?php

namespace App\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Penjelajah data: satu baris = satu isian tugas, dengan filter. Dipakai halaman /laporan/data dan unduhannya.
 * Hanya departemen di `$ids`; semua nilai filter dari pengguna dipakai sebagai binding.
 */
class DataExplorer
{
    public const PER_PAGE = 50;

    public const EXPORT_LIMIT = 50000;

    public const STATUSES = ['done', 'ongoing', 'pending', 'empty'];

    /** @return array{department_id: ?int, position: ?string, employee_id: ?int, status: ?string, has_note: bool, q: ?string} */
    public function filters(Request $request): array
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        return [
            'department_id' => $request->filled('department_id') ? (int) $request->query('department_id') : null,
            'position' => $request->filled('position') ? (string) $request->query('position') : null,
            'employee_id' => $request->filled('employee_id') ? (int) $request->query('employee_id') : null,
            'status' => in_array($status, self::STATUSES, true) ? $status : null,
            'has_note' => $request->boolean('has_note'),
            'q' => $q !== '' ? mb_substr($q, 0, 100) : null,
        ];
    }

    public function paginate(array $ids, array $range, array $filters, int $page): LengthAwarePaginator
    {
        $query = $this->query($ids, $range, $filters);
        $total = (clone $query)->count('i.id');
        $rows = $query->orderByDesc('c.date')->orderBy('d.name')->orderBy('e.name')->orderBy('i.sort')->orderBy('i.id')
            ->forPage($page, self::PER_PAGE)->get($this->columns());

        return new LengthAwarePaginator($rows, $total, self::PER_PAGE, $page);
    }

    /** Baris untuk Excel (dibatasi EXPORT_LIMIT). */
    public function all(array $ids, array $range, array $filters)
    {
        return $this->query($ids, $range, $filters)->orderBy('c.date')->orderBy('d.name')->orderBy('e.name')->orderBy('i.sort')->orderBy('i.id')
            ->limit(self::EXPORT_LIMIT)->get($this->columns());
    }

    /** @return list<string> */
    public function positions(array $ids, array $range, ?int $departmentId): array
    {
        return DB::table('checklists')->whereIn('department_id', $ids)->whereBetween('date', $range)
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->distinct()->orderBy('position_name')->pluck('position_name')->all();
    }

    /** @return array<int, string> id => nama, pegawai yang punya checklist di rentang dan departemen itu. */
    public function employees(array $ids, array $range, ?int $departmentId): array
    {
        return DB::table('checklists as c')->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->whereIn('c.department_id', $ids)->whereBetween('c.date', $range)
            ->when($departmentId !== null, fn ($q) => $q->where('c.department_id', $departmentId))
            ->distinct()->orderBy('e.name')->pluck('e.name', 'e.id')->all();
    }

    private function query(array $ids, array $range, array $filters): Builder
    {
        return DB::table('checklist_items as i')
            ->join('checklists as c', 'c.id', '=', 'i.checklist_id')
            ->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->join('departments as d', 'd.id', '=', 'c.department_id')
            ->whereIn('c.department_id', $ids)->whereBetween('c.date', $range)
            ->when($filters['department_id'], fn ($q, $v) => $q->where('c.department_id', $v))
            ->when($filters['position'], fn ($q, $v) => $q->where('c.position_name', $v))
            ->when($filters['employee_id'], fn ($q, $v) => $q->where('c.employee_id', $v))
            ->when($filters['status'], fn ($q, $v) => $v === 'empty' ? $q->whereNull('i.status') : $q->where('i.status', $v))
            ->when($filters['has_note'], fn ($q) => $q->whereNotNull('i.note')->where('i.note', '!=', ''))
            ->when($filters['q'], function ($q, $v) {
                $like = '%'.addcslashes($v, '%_\\').'%';

                return $q->where(fn ($w) => $w->where('i.title', 'like', $like)->orWhere('i.note', 'like', $like));
            });
    }

    /** @return list<mixed> */
    private function columns(): array
    {
        return [
            'c.id as checklist_id', 'c.date', 'd.name as department', 'c.position_name as position', 'e.name as employee',
            'i.title', 'i.status', 'i.note', 'c.status as review',
            DB::raw('(select count(*) from checklist_item_photos p where p.checklist_item_id = i.id) as photos'),
            DB::raw("(select group_concat(concat(p.name, ': ', p.value, coalesce(concat(' ', p.unit), '')) order by p.sort separator '; ') from checklist_item_parameters p where p.checklist_item_id = i.id and p.type <> 'status' and p.value is not null and p.value <> '') as params"),
        ];
    }
}

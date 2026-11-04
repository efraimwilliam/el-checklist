<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Menyimpan ringkasan harian (departemen x jabatan) ke `report_snapshots`; aman diulang untuk tanggal yang sama. */
class SnapshotService
{
    /** @return int jumlah baris yang ditulis */
    public function build(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $rows = DB::table('checklist_items as i')->join('checklists as c', 'c.id', '=', 'i.checklist_id')
            ->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('c.date', 'c.department_id', 'c.position_name')
            ->selectRaw("c.date, c.department_id, c.position_name, count(distinct c.id) as checklists, count(i.id) as items,
                coalesce(sum(i.status = 'done'), 0) as done, coalesce(sum(i.status = 'ongoing'), 0) as ongoing,
                coalesce(sum(i.status = 'pending'), 0) as pending, coalesce(sum(i.status is null), 0) as blank")
            ->get();

        $now = now();
        $data = $rows->map(fn ($r) => [
            'date' => $r->date, 'department_id' => $r->department_id, 'position_name' => $r->position_name,
            'checklists' => $r->checklists, 'items' => $r->items, 'done' => $r->done, 'ongoing' => $r->ongoing,
            'pending' => $r->pending, 'blank' => $r->blank, 'created_at' => $now, 'updated_at' => $now,
        ])->all();

        foreach (array_chunk($data, 500) as $chunk) {
            DB::table('report_snapshots')->upsert($chunk, ['date', 'department_id', 'position_name'], ['checklists', 'items', 'done', 'ongoing', 'pending', 'blank', 'updated_at']);
        }

        return count($data);
    }

    /** Tanggal checklist paling awal (untuk --backfill), atau null bila belum ada checklist. */
    public function earliest(): ?CarbonImmutable
    {
        $date = DB::table('checklists')->min('date');

        return $date ? CarbonImmutable::parse($date) : null;
    }
}

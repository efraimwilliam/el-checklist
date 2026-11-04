<?php

namespace App\Console\Commands;

use App\Reports\SnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Menyimpan ringkasan harian untuk laporan bulanan. Bawaan: 7 hari terakhir (isian yang terlambat ikut terhitung ulang). */
class SnapshotReport extends Command
{
    protected $signature = 'laporan:snapshot {--dari= : Tanggal mulai (Y-m-d)} {--sampai= : Tanggal akhir (Y-m-d)} {--backfill : Dari checklist paling awal}';

    protected $description = 'Simpan ringkasan harian per departemen dan jabatan untuk laporan bulanan';

    public function handle(SnapshotService $snapshots): int
    {
        $to = $this->option('sampai') ? CarbonImmutable::parse($this->option('sampai')) : CarbonImmutable::today();
        $from = match (true) {
            (bool) $this->option('dari') => CarbonImmutable::parse($this->option('dari')),
            (bool) $this->option('backfill') => $snapshots->earliest() ?? $to,
            default => $to->subDays(6),
        };
        $written = $snapshots->build($from, $to);
        $this->info("{$written} baris ringkasan disimpan ({$from->toDateString()} sampai {$to->toDateString()}).");

        return self::SUCCESS;
    }
}

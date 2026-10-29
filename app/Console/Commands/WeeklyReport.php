<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Reports\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Ringkasan mingguan (7 hari sampai kemarin) untuk semua departemen, disimpan di storage/app/private/laporan.
 * Pengiriman email belum ada: alamat email GM perlu disiapkan dulu. Dibuat atas nama Administrator (atau GM bila tidak ada).
 */
class WeeklyReport extends Command
{
    protected $signature = 'laporan:mingguan';

    protected $description = 'Buat ringkasan laporan mingguan (7 hari sampai kemarin) dan simpan sebagai berkas teks';

    public function handle(ReportService $reports): int
    {
        $user = User::where('role', Role::Admin)->where('is_active', true)->first()
            ?? User::where('role', Role::Gm)->where('is_active', true)->first();
        if (! $user) {
            $this->error('Belum ada Administrator atau GM aktif.');

            return self::FAILURE;
        }

        $to = CarbonImmutable::yesterday();
        $from = $to->subDays(6);
        $report = $reports->build($user, $from, $to);
        $kpi = $report['kpi'];

        $lines = [
            "Ringkasan mingguan Checklist Kerjaan",
            "Periode: {$from->translatedFormat('d M Y')} – {$to->translatedFormat('d M Y')}",
            '',
            'Skor rata-rata: '.($kpi['score'] !== null ? $kpi['score'].'%' : '—')
                .' · checklist lengkap: '.($kpi['completion'] !== null ? $kpi['completion'].'%' : '—')
                .' ('.$kpi['complete'].' dari '.$kpi['checklists'].')',
            "Tugas Pending: {$kpi['pending']} · Ongoing: {$kpi['ongoing']}",
            "Belum direview: {$kpi['unreviewed']} · backlog (lebih dari 2 hari): {$kpi['backlog']}",
            "Staff belum punya checklist: {$kpi['missing']} · foto bukti kurang: {$kpi['photos']}",
            '',
            'Per departemen:',
        ];
        foreach ($report['rows'] as $row) {
            $lines[] = "- {$row['name']}: {$row['checklists']} checklist, lengkap ".($row['completion'] !== null ? $row['completion'].'%' : '—').", pending {$row['pending']}, belum direview {$row['unreviewed']}";
        }
        $lines[] = '';
        $lines[] = 'Jabatan dengan skor terendah:';
        foreach (array_slice($report['positions'], 0, 5) as $row) {
            $lines[] = "- {$row['position']} ({$row['department']}): ".($row['score'] !== null ? $row['score'].'%' : '—');
        }
        $lines[] = '';
        $lines[] = 'Tugas sering dilewati:';
        foreach (array_slice($report['skipped'], 0, 5) as $row) {
            $lines[] = "- {$row['employee']} · {$row['title']}: {$row['times']}×";
        }

        $path = 'laporan/mingguan-'.$to->toDateString().'.txt';
        Storage::disk('local')->put($path, implode("\n", $lines)."\n");
        $this->info("Ringkasan disimpan di storage/app/private/{$path}");

        return self::SUCCESS;
    }
}

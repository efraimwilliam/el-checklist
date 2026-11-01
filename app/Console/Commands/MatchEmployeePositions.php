<?php

namespace App\Console\Commands;

use App\Checklists\PositionSuggester;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Console\Command;

/**
 * Mengisi jabatan checklist pegawai dari teks jabatan di Absensi (aturan yang sama dengan saran di halaman Jabatan).
 * Yang tidak cocok dibiarkan kosong untuk diatur HOD. Tanpa --terapkan hanya simulasi.
 */
class MatchEmployeePositions extends Command
{
    protected $signature = 'checklist:cocokkan-jabatan {--terapkan : Simpan jabatan yang cocok; tanpa ini hanya simulasi} {--timpa : Ganti juga jabatan yang sudah diatur}';

    protected $description = 'Isi jabatan checklist pegawai dari jabatan di Absensi';

    public function handle(PositionSuggester $suggester): int
    {
        $positions = Position::where('is_active', true)->orderBy('name')->get()->groupBy('department_id');
        $employees = Employee::with('department')->where('is_active', true)->whereNotNull('external_id')->whereNotNull('department_id')
            ->when(! $this->option('timpa'), fn ($query) => $query->whereNull('checklist_position_id'))
            ->orderBy('name')->get();

        $matched = 0;
        $empty = 0;
        $noTitle = 0;
        $updates = [];

        foreach ($employees->groupBy(fn (Employee $e) => $e->department->name) as $department => $group) {
            $rows = [];
            foreach ($group->groupBy(fn (Employee $e) => (string) $e->position_title) as $title => $same) {
                if ($title === '') {
                    $noTitle += $same->count();

                    continue;
                }
                $position = $suggester->suggest($same->first(), $positions->get($same->first()->department_id, collect()));
                $position ? $matched += $same->count() : $empty += $same->count();
                foreach ($same as $employee) {
                    $position && $updates[$employee->id] = $position->id;
                }
                $rows[] = [$title, $same->count(), $position?->name ?? '—  (dikosongkan)'];
            }
            if ($rows) {
                $this->line("<options=bold>{$department}</>");
                $this->table(['Jabatan di Absensi', 'Pegawai', 'Jabatan checklist'], $rows);
            }
        }

        if ($this->option('terapkan')) {
            foreach ($updates as $employeeId => $positionId) {
                // Teks jabatan Absensi dicatat sebagai acuan: bila nanti berubah, pegawai ditandai "perlu dicek".
                Employee::whereKey($employeeId)->update(['checklist_position_id' => $positionId, 'checklist_title' => Employee::whereKey($employeeId)->value('position_title')]);
            }
        }

        $this->info(($this->option('terapkan') ? 'Tersimpan' : 'SIMULASI (belum disimpan)').": {$matched} pegawai dicocokkan, {$empty} dikosongkan (diatur HOD), {$noTitle} tanpa teks jabatan.");
        if (! $this->option('terapkan')) {
            $this->line('Jalankan lagi dengan --terapkan untuk menyimpan.');
        }

        return self::SUCCESS;
    }
}

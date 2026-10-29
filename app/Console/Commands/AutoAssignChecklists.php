<?php

namespace App\Console\Commands;

use App\Attendance\AttendanceUnavailable;
use App\Checklists\BulkAssignService;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pembuatan otomatis checklist harian. Dijalankan tiap 5 menit; tiap departemen yang jam otomatisnya (WITA) sudah lewat dan belum
 * dijalankan hari ini dibuatkan checklistnya. Gagal membaca jadwal = dicoba lagi 5 menit kemudian, tidak ditandai selesai.
 */
class AutoAssignChecklists extends Command
{
    protected $signature = 'checklist:auto-assign';

    protected $description = 'Buatkan checklist hari ini untuk departemen yang jam otomatisnya sudah tiba';

    public function handle(BulkAssignService $service): int
    {
        $today = CarbonImmutable::today();
        $now = now()->format('H:i');

        $due = Department::where('is_active', true)->whereNotNull('auto_assign_at')
            ->where(fn ($q) => $q->whereNull('auto_assigned_on')->orWhereDate('auto_assigned_on', '<', $today))
            ->get()->filter(fn (Department $department) => substr((string) $department->auto_assign_at, 0, 5) <= $now);

        foreach ($due as $department) {
            try {
                $summary = $service->run([$department->id], $today, null);
            } catch (AttendanceUnavailable $e) {
                Log::warning('Auto-assign checklist gagal membaca jadwal', ['department' => $department->code, 'message' => $e->getMessage()]);
                $this->warn("{$department->name}: jadwal belum bisa dibaca, dicoba lagi nanti.");

                continue;
            }
            $department->update(['auto_assigned_on' => $today]);
            $this->info("{$department->name}: {$summary['created']} dibuat, {$summary['existing']} sudah ada.");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Checklists\ChecklistService;
use App\Enums\ChecklistStatus;
use App\Models\Checklist;
use Illuminate\Console\Command;

/**
 * Menyamakan checklist lama yang belum direview dengan daftar tugas departemen: tugas yang belum ada ditambahkan di akhir.
 * Isian yang sudah ada tidak diubah; checklist yang sudah direview tidak disentuh. Tanpa --terapkan hanya simulasi.
 */
class AlignDepartmentTasks extends Command
{
    protected $signature = 'checklist:samakan-tugas {--terapkan : Tambahkan tugas yang kurang; tanpa ini hanya simulasi}';

    protected $description = 'Samakan checklist yang belum direview dengan daftar tugas departemen';

    public function handle(ChecklistService $checklists): int
    {
        $apply = (bool) $this->option('terapkan');
        $checklistCount = 0;
        $taskCount = 0;

        Checklist::with('employee')->where('status', ChecklistStatus::Saved)
            ->orderBy('date')->chunkById(200, function ($batch) use ($checklists, $apply, &$checklistCount, &$taskCount) {
                foreach ($batch as $checklist) {
                    if ($checklist->employee->custom_tasks) {
                        continue;
                    }
                    if (! $apply) {
                        $present = $checklist->items()->pluck('task_id');
                        $missing = $checklists->departmentTasks($checklist->department_id)->reject(fn ($task) => $present->contains($task->id))->count();
                        if ($missing === 0) {
                            continue;
                        }
                        $taskCount += $missing;
                        $checklistCount++;

                        continue;
                    }
                    $added = $checklists->addDepartmentTasks($checklist);
                    if ($added > 0) {
                        $taskCount += $added;
                        $checklistCount++;
                    }
                }
            });

        $this->info(($apply ? 'Tersimpan' : 'SIMULASI (belum disimpan)').": {$taskCount} tugas ditambahkan ke {$checklistCount} checklist yang belum direview.");

        return self::SUCCESS;
    }
}

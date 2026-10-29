<?php

namespace App\Console\Commands;

use App\Checklists\ChecklistPhotoService;
use App\Checklists\ChecklistSettings;
use Illuminate\Console\Command;

/** Menghapus foto yang melewati masa simpan (Pengaturan). Isian checklist tetap ada; hanya fotonya yang dihapus. */
class PruneChecklistPhotos extends Command
{
    protected $signature = 'checklist:prune-photos';

    protected $description = 'Hapus foto checklist yang melewati masa simpan';

    public function handle(ChecklistSettings $settings, ChecklistPhotoService $photos): int
    {
        $days = $settings->photoRetentionDays();
        if ($days === 0) {
            $this->info('Masa simpan foto: tidak pernah dihapus.');

            return self::SUCCESS;
        }
        $this->info("Foto dihapus (lebih dari {$days} hari): {$photos->prune($days)}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Checklists\ChecklistPhotoService;
use App\Checklists\ChecklistSettings;
use App\Health\SystemHealth;
use Illuminate\Support\Facades\Cache;
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
        $deleted = $photos->prune($days);
        Cache::forever(SystemHealth::PHOTO_PRUNE_CACHE, ['at' => now()->toIso8601String(), 'deleted' => $deleted]);
        $this->info("Foto dihapus (lebih dari {$days} hari): {$deleted}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Checklist;
use Illuminate\Console\Command;

/** "Mulai checklist" langsung membuat checklist kosong; yang ditinggalkan tanpa satu pun isian dibersihkan supaya tidak menumpuk di riwayat. */
class PruneEmptyChecklists extends Command
{
    protected $signature = 'checklist:prune-empty {--days=2 : Umur minimal (hari) sejak terakhir diubah}';

    protected $description = 'Hapus checklist yang tidak punya satu pun isian (status, tugas tambahan, catatan)';

    public function handle(): int
    {
        $deleted = Checklist::where('status', 'saved')
            ->where('updated_at', '<', now()->subDays((int) $this->option('days')))
            ->whereNull('note')
            ->whereDoesntHave('extraTasks')
            ->whereDoesntHave('items', fn ($q) => $q->whereNotNull('status')->orWhereHas('photos'))
            ->delete();

        $this->info("Checklist kosong dihapus: {$deleted}.");

        return self::SUCCESS;
    }
}

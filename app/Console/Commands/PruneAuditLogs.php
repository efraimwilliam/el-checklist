<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/** Menghapus log aplikasi yang sudah lewat masa simpan (config/audit.php). */
class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Hapus log aplikasi yang sudah lewat masa simpan';

    public function handle(): int
    {
        $days = max(1, (int) config('audit.retention_days', 180));
        $deleted = AuditLog::where('occurred_at', '<', now()->subDays($days))->delete();
        $this->info("{$deleted} log lebih tua dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}

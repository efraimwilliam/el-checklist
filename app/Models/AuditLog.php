<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu catatan aktivitas: siapa, kapan, apa, pada objek apa, dan perubahan (lama → baru). Tidak pernah memuat PIN. */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['occurred_at', 'user_id', 'user_name', 'action', 'subject_type', 'subject_id', 'subject_label', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'changes' => 'array'];
    }
}

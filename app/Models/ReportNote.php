<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan tindak lanjut pada laporan: tiap catatan berlaku untuk satu rentang tanggal (dan departemen, atau semua departemen bila kosong). */
class ReportNote extends Model
{
    protected $fillable = ['department_id', 'period_from', 'period_to', 'body', 'created_by'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

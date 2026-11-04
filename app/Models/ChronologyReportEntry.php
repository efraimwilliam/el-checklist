<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu poin bernomor di Chronology Report (urutan = `sort`); `occurred_at` hanya acuan di layar, tidak masuk teks laporan. */
class ChronologyReportEntry extends Model
{
    protected $fillable = ['chronology_report_id', 'sort', 'occurred_at', 'body'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}

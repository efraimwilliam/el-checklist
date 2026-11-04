<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu tamu (nama + nomor kamar) pada Chronology Report. */
class ChronologyReportGuest extends Model
{
    protected $fillable = ['chronology_report_id', 'sort', 'name', 'room_number'];
}

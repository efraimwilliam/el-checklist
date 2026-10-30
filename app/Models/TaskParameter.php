<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parameter isian sebuah tugas (templat). Checklist menyalin parameter ini ke item saat dibuat. */
class TaskParameter extends Model
{
    protected $fillable = ['task_id', 'sort', 'name', 'type', 'unit', 'min_value', 'max_value', 'options', 'normal_option'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}

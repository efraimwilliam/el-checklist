<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Templat tugas sebuah jabatan. */
class Task extends Model
{
    use HasFactory;

    protected $fillable = ['position_id', 'section', 'title', 'standard', 'remarks', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** Parameter isian tugas, urut. */
    public function parameters(): HasMany
    {
        return $this->hasMany(TaskParameter::class)->orderBy('sort')->orderBy('id');
    }
}

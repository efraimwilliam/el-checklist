<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Denah (gambar) satu lantai / gedung, tempat kotak area kamar ditandai untuk Peta kamar. */
class FloorPlan extends Model
{
    protected $fillable = ['name', 'floor', 'image_path', 'width', 'height', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function areas(): HasMany
    {
        return $this->hasMany(FloorPlanArea::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kotak area di atas denah (x, y, w, h dalam persen dari ukuran gambar): satu kamar (`room_id`) atau satu gedung / area (`building`). */
class FloorPlanArea extends Model
{
    protected $fillable = ['floor_plan_id', 'room_id', 'building', 'x', 'y', 'w', 'h'];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'w' => 'float', 'h' => 'float'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class, 'floor_plan_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['external_id', 'code', 'name', 'sort', 'is_active', 'auto_assign_at', 'auto_assigned_on'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'auto_assigned_on' => 'date'];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class)->orderBy('sort')->orderBy('name');
    }
}

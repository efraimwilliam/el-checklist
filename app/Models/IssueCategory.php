<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kategori kendala kamar (AC, listrik, kebersihan, ...) dengan departemen penanggung jawab bawaan. */
class IssueCategory extends Model
{
    protected $fillable = ['name', 'department_id', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function issues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RoomIssue::class, 'category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}

<?php

namespace App\Models;

use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistItem extends Model
{
    protected $fillable = ['checklist_id', 'task_id', 'section', 'title', 'standard', 'sort', 'status', 'note'];

    protected function casts(): array
    {
        return ['status' => ItemStatus::class];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ChecklistItemPhoto::class)->orderBy('id');
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /** Salinan parameter item, urut. */
    public function parameters(): HasMany
    {
        return $this->hasMany(ChecklistItemParameter::class)->orderBy('sort')->orderBy('id');
    }
}

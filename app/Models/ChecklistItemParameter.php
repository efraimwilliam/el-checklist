<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Salinan parameter pada satu item checklist, beserta nilai yang diisi. */
class ChecklistItemParameter extends Model
{
    protected $fillable = ['checklist_item_id', 'task_parameter_id', 'sort', 'name', 'type', 'unit', 'min_value', 'max_value', 'options', 'normal_option', 'value'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }
}

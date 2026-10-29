<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItemPhoto extends Model
{
    protected $fillable = ['checklist_item_id', 'path', 'size', 'created_by'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    /** Alamat gambar (dilayani aplikasi dengan pemeriksaan akses). */
    public function url(): string
    {
        return route('checklists.photos.show', [$this->item->checklist_id, $this->id]);
    }
}

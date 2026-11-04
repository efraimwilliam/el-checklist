<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tindak lanjut satu isian tugas Pending: status (open / handling / resolved) dan catatan penangan. */
class IssueFollowup extends Model
{
    public const STATUSES = ['open', 'handling', 'resolved'];

    protected $fillable = ['checklist_item_id', 'status', 'note', 'updated_by'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

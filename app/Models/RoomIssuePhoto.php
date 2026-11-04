<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto bukti pada satu catatan pekerjaan kendala kamar. */
class RoomIssuePhoto extends Model
{
    protected $fillable = ['room_issue_update_id', 'path', 'size', 'created_by'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(RoomIssueUpdate::class, 'room_issue_update_id');
    }
}

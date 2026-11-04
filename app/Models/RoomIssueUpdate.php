<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catatan pekerjaan di kamar, atau jejak ambil / selesai. */
class RoomIssueUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['room_issue_id', 'user_id', 'department_id', 'body', 'kind', 'related_issue_id'];

    public function photos(): HasMany
    {
        return $this->hasMany(RoomIssuePhoto::class, 'room_issue_update_id')->orderBy('id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(RoomIssue::class, 'room_issue_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}

<?php

namespace App\Models;

use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Model;

/** Pekerjaan di luar daftar tugas jabatan (mis. membantu banquet). */
class ChecklistExtraTask extends Model
{
    protected $fillable = ['checklist_id', 'title', 'status', 'note', 'sort'];

    protected function casts(): array
    {
        return ['status' => ItemStatus::class];
    }
}

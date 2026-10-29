<?php

namespace App\Checklists;

use App\Models\Checklist;
use RuntimeException;

/** Checklist untuk staff + jabatan + tanggal itu sudah ada (mis. disimpan bersamaan dari dua perangkat). */
class DuplicateChecklist extends RuntimeException
{
    public function __construct(public readonly Checklist $existing)
    {
        parent::__construct('Checklist sudah ada.');
    }
}

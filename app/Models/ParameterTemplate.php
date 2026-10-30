<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jenis isian yang pernah dibuat, dipakai ulang di tugas lain. Unik per nama dan jenis. */
class ParameterTemplate extends Model
{
    protected $fillable = ['name', 'type', 'unit', 'min_value', 'max_value', 'options', 'normal_option'];
}

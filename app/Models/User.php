<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Akses aplikasi seorang pegawai. Tidak ada password/PIN: login diverifikasi Sistem Absensi,
 * lalu sesi Laravel biasa memakai akun ini. Nama & departemen dibaca dari employee.
 */
class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['employee_id', 'role', 'position_id', 'is_active', 'last_login_at'];

    // Tidak ada kolom password / remember_token.
    protected $rememberTokenName = '';

    protected function casts(): array
    {
        return ['role' => Role::class, 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** Departemen yang ditugaskan untuk HOD. */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    /** Akun aktif dan pegawainya masih aktif di Sistem Absensi. */
    public function canUseApp(): bool
    {
        return $this->is_active && $this->employee?->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function canAccessDepartment(?int $departmentId): bool
    {
        if ($this->role->seesAllDepartments()) {
            return true;
        }

        return $this->role === Role::Hod && $departmentId !== null
            && $this->departments->contains('id', $departmentId);
    }

    /** @return list<int>|null null = semua departemen */
    public function accessibleDepartmentIds(): ?array
    {
        if ($this->role->seesAllDepartments()) {
            return null;
        }

        return $this->role === Role::Hod ? $this->departments->pluck('id')->all() : [];
    }

    public function name(): string
    {
        return $this->employee?->name ?? '—';
    }
}

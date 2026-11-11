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

    // Tidak ada kolom remember_token. Password hanya ada untuk akun sistem (lihat checklist:admin-lokal).
    protected $rememberTokenName = '';

    protected $hidden = ['password'];

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

    /** Departemen checklist pegawai ini: departemen di absensi, atau departemen jabatannya bila belum ada. */
    public function checklistDepartmentId(): ?int
    {
        return $this->employee?->department_id ?? $this->position?->department_id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    /** Akun sistem dengan password Checklist sendiri (bukan pegawai absensi). Password-nya diubah di Profil, tidak lewat Absensi. */
    public function hasLocalPassword(): bool
    {
        return filled($this->password) && $this->employee?->external_id === null;
    }

    /** Akun aktif dan pegawainya masih aktif di Sistem Absensi. */
    public function canUseApp(): bool
    {
        return $this->is_active && $this->employee?->is_active;
    }

    /**
     * Departemen yang menangani kendala kamar untuk pengguna ini: departemennya sendiri, ditambah departemen yang dipegang HOD.
     *
     * @return list<int>
     */
    public function issueDepartmentIds(): array
    {
        $ids = $this->hodDepartmentIds();
        if ($own = $this->checklistDepartmentId()) {
            $ids[] = $own;
        }

        return array_values(array_unique($ids));
    }

    /** Kendala berstatus baru yang diteruskan kepada pengguna ini: ke departemennya, jabatannya, atau dirinya (penanda di menu Kendala). */
    public function newRoomIssueCount(): int
    {
        return once(function () {
            if ($this->issueDepartmentIds() === [] && ! $this->employee_id) {
                return 0;
            }

            return \App\Models\RoomIssue::where('status', \App\Models\RoomIssue::STATUS_NEW)->forUser($this)->count();
        });
    }

    /** Jumlah percakapan yang punya pesan baru dari orang lain (satu query per permintaan; 0 bila obrolan dimatikan). */
    public function unreadChatCount(): int
    {
        return once(function () {
            if (! \Illuminate\Support\Facades\Gate::forUser($this)->allows('chat')) {
                return 0;
            }

            return \App\Models\ChatParticipant::where('user_id', $this->id)
                ->where(fn ($outer) => $outer->where('marked_unread', true)
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('chat_messages')
                        ->whereColumn('chat_messages.chat_thread_id', 'chat_participants.chat_thread_id')
                        ->whereColumn('chat_messages.id', '>', 'chat_participants.last_read_message_id')
                        ->where('chat_messages.user_id', '!=', $this->id)
                        ->whereNull('chat_messages.deleted_at')))
                ->count();
        });
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
            && in_array($departmentId, $this->hodDepartmentIds(), true);
    }

    /** @return list<int>|null null = semua departemen */
    public function accessibleDepartmentIds(): ?array
    {
        if ($this->role->seesAllDepartments()) {
            return null;
        }

        return $this->hodDepartmentIds();
    }

    /**
     * Departemen yang dipegang HOD: hanya yang dicentang Administrator di Akses pengguna (bukan dari departemen / jabatan Absensi).
     * Kosong untuk peran lain.
     *
     * @return list<int>
     */
    public function hodDepartmentIds(): array
    {
        return $this->role === Role::Hod ? $this->departments->pluck('id')->all() : [];
    }

    /** HOD yang belum dicentang departemennya di Akses pengguna: belum bisa mengatur tugas atau checklist siapa pun. */
    public function isHodWithoutDepartment(): bool
    {
        return $this->role === Role::Hod && $this->hodDepartmentIds() === [];
    }

    public function name(): string
    {
        return $this->employee?->name ?? '—';
    }
}

<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kendala di satu lokasi (kamar, atau tempat lain seperti Lobby / Restoran): dicatat (biasanya oleh Front Office), diteruskan ke
 * departemen, jabatan, atau pegawai tertentu, lalu yang menangani mencatat pekerjaannya sampai selesai. Kendala di kamar
 * menjadi dasar Peta kamar di Laporan.
 */
class RoomIssue extends Model
{
    public const STATUS_NEW = 'baru';

    public const STATUS_WORKING = 'dikerjakan';

    public const STATUS_PENDING = 'tertunda';

    public const STATUS_DONE = 'selesai';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_WORKING, self::STATUS_PENDING, self::STATUS_DONE];

    public const SOURCES = ['tamu', 'staff', 'inspeksi'];

    /** Kendala dengan kategori yang sama di kamar yang sama dalam jangka ini dianggap berulang ("berulang ke-N"). */
    public const RECURRENCE_DAYS = 30;

    public const PRIORITIES = ['biasa', 'mendesak'];

    /** Saran lokasi selain kamar (selain yang pernah dipakai); lokasi tetap boleh diketik bebas. */
    public const SUGGESTED_LOCATIONS = ['Lobby', 'Restoran', 'Lounge bar', 'Kolam renang', 'Spa', 'Gym', 'Koridor', 'Tangga', 'Area parkir', 'Taman', 'Ruang meeting', 'Dapur', 'Area staff', 'Toilet umum'];

    protected $fillable = ['room_id', 'location', 'category_id', 'source', 'description', 'priority', 'recurrence', 'reported_by', 'assigned_department_id', 'assigned_position_id', 'assigned_employee_id', 'claimed_by', 'claimed_at', 'status', 'completed_at', 'completed_by'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        // Sama dengan istilah di checklist: Ongoing = sedang dikerjakan, Pending = tertunda (wajib beralasan), Done = selesai.
        return [self::STATUS_NEW => __('Baru'), self::STATUS_WORKING => 'Ongoing', self::STATUS_PENDING => 'Pending', self::STATUS_DONE => 'Done'];
    }

    /** @return array<string, string> */
    public static function sourceLabels(): array
    {
        return ['tamu' => __('Laporan tamu'), 'staff' => __('Ditemukan staff'), 'inspeksi' => __('Inspeksi')];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IssueCategory::class, 'category_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** Pegawai yang mengambil kendala ini (satu orang per kendala). */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assigned_department_id');
    }

    public function assignedPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'assigned_position_id');
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    /** Nama lokasi: nomor kamar, atau teks lokasi lain. */
    public function placeName(): string
    {
        return $this->room?->number ?? (string) $this->location;
    }

    /** Lokasi untuk judul: "Kamar 305" atau nama lokasi lain. */
    public function placeTitle(): string
    {
        return $this->room_id ? __('Kamar').' '.$this->room?->number : (string) $this->location;
    }

    /** Diteruskan ke siapa: departemen, "Jabatan · Departemen", atau "Pegawai · Departemen". */
    public function targetLabel(): string
    {
        $department = $this->department?->name;
        if ($this->assigned_employee_id) {
            return trim(($this->assignedEmployee?->name ?? '—').($department ? ' · '.$department : ''));
        }
        if ($this->assigned_position_id) {
            return trim(($this->assignedPosition?->name ?? '—').($department ? ' · '.$department : ''));
        }

        return $department ?? __('Belum ditugaskan');
    }

    /** "departemen", "jabatan", atau "pegawai". */
    public function targetKind(): string
    {
        return $this->assigned_employee_id ? 'pegawai' : ($this->assigned_position_id ? 'jabatan' : 'departemen');
    }

    /** Kendala di lokasi yang sama: kamar yang sama, atau (bukan kamar) teks lokasi yang sama tanpa membedakan huruf besar / kecil. */
    public function scopeAtPlace(Builder $query, ?int $roomId, ?string $location): Builder
    {
        return $roomId
            ? $query->where('room_id', $roomId)
            : $query->whereNull('room_id')->where('location', trim((string) $location));
    }

    public function isAtPlace(?int $roomId, ?string $location): bool
    {
        return $roomId
            ? $this->room_id === $roomId
            : $this->room_id === null && mb_strtolower(trim((string) $this->location)) === mb_strtolower(trim((string) $location));
    }

    /**
     * Kendala ini tugas pengguna: ditujukan ke pegawainya, ke jabatan checklistnya, atau ke departemennya (bila tujuannya departemen saja).
     * HOD ikut memegang semua tujuan di departemen yang dipegangnya.
     */
    public function isForUser(User $user): bool
    {
        if ($this->assigned_employee_id !== null && $this->assigned_employee_id === $user->employee_id) {
            return true;
        }
        if ($this->assigned_position_id !== null && $this->assigned_position_id === $user->employee?->checklist_position_id) {
            return true;
        }
        $department = $this->assigned_department_id;
        if ($department === null) {
            return false;
        }
        if ($user->role === Role::Hod && in_array($department, $user->hodDepartmentIds(), true)) {
            return true;
        }

        return $this->assigned_position_id === null && $this->assigned_employee_id === null && in_array($department, $user->issueDepartmentIds(), true);
    }

    /** Sama dengan isForUser, sebagai penyaring query (dipakai daftar, penanda menu, dan hak lihat). */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        $departments = $user->issueDepartmentIds();
        $managed = $user->hodDepartmentIds();
        $positionId = $user->employee?->checklist_position_id;

        return $query->where(function (Builder $q) use ($departments, $managed, $user, $positionId) {
            $q->where(fn (Builder $d) => $d->whereNull('assigned_position_id')->whereNull('assigned_employee_id')->whereIn('assigned_department_id', $departments))
                ->orWhereIn('assigned_department_id', $managed)
                ->when($user->employee_id, fn (Builder $e, $id) => $e->orWhere('assigned_employee_id', $id))
                ->when($positionId, fn (Builder $p, $id) => $p->orWhere('assigned_position_id', $id));
        });
    }

    public function updates(): HasMany
    {
        return $this->hasMany(RoomIssueUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    /** Nomor berulang untuk kendala baru di lokasi dan kategori ini: 1 + kendala serupa dalam RECURRENCE_DAYS terakhir. */
    public static function nextRecurrence(?int $roomId, ?string $location, ?int $categoryId): int
    {
        if ($categoryId === null) {
            return 1;
        }

        return min(255, static::atPlace($roomId, $location)->where('category_id', $categoryId)->where('created_at', '>=', now()->subDays(self::RECURRENCE_DAYS))->count() + 1);
    }

    /** Kendala serupa (lokasi dan kategori sama) yang belum selesai, bila ada. */
    public static function openDuplicate(?int $roomId, ?string $location, ?int $categoryId): ?self
    {
        if ($categoryId === null) {
            return null;
        }

        return static::atPlace($roomId, $location)->where('category_id', $categoryId)->where('status', '!=', self::STATUS_DONE)->latest('id')->first();
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /** Admin / HR / GM melihat semua; lainnya hanya yang dilaporkan sendiri atau yang menjadi tugasnya (departemen, jabatan, atau pegawai). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->seesAllDepartments()) {
            return $query;
        }

        // Pelapor, yang kendalanya diteruskan kepadanya, atau yang pernah menambahkan laporan / catatan pada kendala itu.
        return $query->where(fn (Builder $q) => $q->where('reported_by', $user->id)
            ->orWhere(fn (Builder $f) => $f->forUser($user))
            ->orWhereHas('updates', fn (Builder $u) => $u->where('user_id', $user->id)));
    }
}

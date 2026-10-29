<?php

namespace App\Checklists;

use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Membuat checklist dan menyimpan isiannya satu per satu (penyimpanan otomatis). Checklist menyalin departemen, jabatan dan isi tugas
 * saat dibuat, jadi riwayat tetap utuh walau pegawai pindah departemen atau daftar tugas diubah / dihapus sesudahnya.
 * Checklist boleh tersimpan setengah jadi; "lengkap" adalah turunan (lihat Checklist::isComplete) dan menentukan bisa tidaknya direview.
 */
class ChecklistService
{
    /**
     * Tugas aktif untuk checklist baru: semua tugas jabatan (bawaan), atau hanya yang dipilih HOD untuk pegawai ini
     * (boleh dari jabatan lain di departemen yang sama), tugas jabatan yang dipilih lebih dulu.
     *
     * @return Collection<int, Task>
     */
    public function tasksFor(Employee $employee, Position $position): Collection
    {
        if (! $employee->custom_tasks) {
            return $position->tasks()->where('is_active', true)->get();
        }

        return Task::query()->select('tasks.*')
            ->join('positions', 'positions.id', '=', 'tasks.position_id')
            ->whereIn('tasks.id', $employee->assignedTasks()->select('tasks.id'))
            ->where('tasks.is_active', true)
            ->orderByRaw('tasks.position_id = ? desc', [$position->id])
            ->orderBy('positions.sort')->orderBy('positions.name')->orderBy('tasks.sort')->orderBy('tasks.id')
            ->get();
    }

    public function find(Employee $employee, Position $position, CarbonImmutable $date): ?Checklist
    {
        return Checklist::where('employee_id', $employee->id)->where('position_id', $position->id)->whereDate('date', $date)->first();
    }

    /** Membuka checklist staff + jabatan + tanggal itu, atau membuatnya (semua tugas belum diisi) bila belum ada. */
    public function start(Employee $employee, Position $position, CarbonImmutable $date, User $by, ?string $checkedBy): Checklist
    {
        try {
            return DB::transaction(function () use ($employee, $position, $date, $by, $checkedBy) {
                if ($existing = $this->find($employee, $position, $date)) {
                    return $existing;
                }

                $checklist = Checklist::create([
                    'employee_id' => $employee->id,
                    'department_id' => $position->department_id,
                    'position_id' => $position->id,
                    'position_name' => $position->name,
                    'date' => $date->toDateString(),
                    'status' => ChecklistStatus::Saved,
                    'checked_by' => $checkedBy,
                    'filled_by' => $by->id,
                ]);
                $checklist->items()->createMany($this->tasksFor($employee, $position)->values()->map(fn (Task $task, int $i) => [
                    'task_id' => $task->id,
                    'section' => $task->section,
                    'title' => $task->title,
                    'standard' => $task->standard,
                    'sort' => $i + 1,
                ])->all());

                return $checklist;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua perangkat memulai bersamaan: yang kedua membuka yang sudah dibuat.
            return $this->find($employee, $position, $date);
        }
    }

    /** Menyimpan satu tugas. Status kosong (null) berarti belum diisi. */
    public function saveItem(ChecklistItem $item, ?string $status, ?string $note, User $by): void
    {
        $item->update(['status' => $status, 'note' => $this->clean($note)]);
        $this->touch($item->checklist, $by);
    }

    /** "Sisa tugas = Done": semua tugas yang belum diisi menjadi Done. */
    public function markRestDone(Checklist $checklist, User $by): void
    {
        $checklist->items()->whereNull('status')->update(['status' => ItemStatus::Done->value]);
        $this->touch($checklist, $by);
    }

    /** "Kosongkan": semua status dan catatan tugas dikosongkan. */
    public function clear(Checklist $checklist, User $by): void
    {
        $checklist->items()->update(['status' => null, 'note' => null]);
        $this->touch($checklist, $by);
    }

    /**
     * Catatan umum, "Dicek oleh" dan tugas tambahan; hanya kunci yang dikirim yang diubah.
     *
     * @param  array{note?: ?string, checked_by?: ?string, extras?: list<array{title?: ?string, status?: ?string, note?: ?string}>}  $meta
     */
    public function saveMeta(Checklist $checklist, User $by, array $meta): void
    {
        DB::transaction(function () use ($checklist, $by, $meta) {
            foreach (['note', 'checked_by'] as $key) {
                if (array_key_exists($key, $meta)) {
                    $checklist->{$key} = $this->clean($meta[$key]);
                }
            }
            if (array_key_exists('extras', $meta)) {
                $checklist->extraTasks()->delete();
                $checklist->extraTasks()->createMany(collect($meta['extras'])->filter(fn ($extra) => filled($extra['title'] ?? null))->values()->map(fn ($extra, $i) => [
                    'title' => trim($extra['title']),
                    'status' => $extra['status'] ?? ItemStatus::Done->value,
                    'note' => $this->clean($extra['note'] ?? null),
                    'sort' => $i + 1,
                ])->all());
            }
            $this->touch($checklist, $by);
        });
    }

    /**
     * Ringkasan untuk bilah progres.
     *
     * @return array{filled: int, total: int, done: int, ongoing: int, pending: int, score: ?int, complete: bool, missing_reasons: int, missing_photos: int}
     */
    public function progress(Checklist $checklist): array
    {
        $checklist->unsetRelation('items')->load('items.photos');
        $counts = $checklist->counts();

        return [
            'filled' => $checklist->answeredCount(),
            'total' => $checklist->items->count(),
            'done' => $counts['done'],
            'ongoing' => $counts['ongoing'],
            'pending' => $counts['pending'],
            'score' => $checklist->score(),
            'complete' => $checklist->isComplete(),
            'missing_reasons' => $checklist->missingReasons(),
            'missing_photos' => $checklist->missingPhotos(),
        ];
    }

    /** Waktu simpan terakhir dan akun yang menyimpan. */
    private function touch(Checklist $checklist, User $by): void
    {
        $checklist->forceFill(['filled_by' => $by->id])->save();
        $checklist->touch();
    }

    private function clean(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }
}

<?php

namespace App\Checklists;

use App\Enums\ChecklistStatus;
use App\Models\Checklist;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Membuat dan memperbarui checklist. Checklist menyalin departemen, jabatan dan isi tugas saat dibuat, jadi riwayat tetap utuh
 * walau pegawai pindah departemen atau daftar tugas diubah / dihapus sesudahnya.
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

    /**
     * Baris form untuk checklist baru (kunci = id tugas).
     *
     * @return list<array{key: int, section: ?string, title: string, standard: ?string, status: ?string, note: ?string}>
     */
    public function rowsForNew(Employee $employee, Position $position): array
    {
        return $this->tasksFor($employee, $position)->map(fn (Task $task) => [
            'key' => $task->id, 'section' => $task->section, 'title' => $task->title, 'standard' => $task->standard, 'status' => null, 'note' => null,
        ])->values()->all();
    }

    /**
     * Baris form untuk checklist yang sudah ada (kunci = id item).
     *
     * @return list<array{key: int, section: ?string, title: string, standard: ?string, status: ?string, note: ?string}>
     */
    public function rowsForExisting(Checklist $checklist): array
    {
        return $checklist->items->map(fn ($item) => [
            'key' => $item->id, 'section' => $item->section, 'title' => $item->title, 'standard' => $item->standard,
            'status' => $item->status?->value, 'note' => $item->note,
        ])->values()->all();
    }

    /**
     * @param  array{items: array<int, array{status: ?string, note: ?string}>, note: ?string, checked_by: ?string, extras: list<array<string, mixed>>}  $input
     *
     * @throws DuplicateChecklist
     */
    public function create(Employee $employee, Position $position, CarbonImmutable $date, User $by, array $input): Checklist
    {
        try {
            return DB::transaction(function () use ($employee, $position, $date, $by, $input) {
                if ($existing = $this->find($employee, $position, $date)) {
                    throw new DuplicateChecklist($existing);
                }

                $checklist = Checklist::create([
                    'employee_id' => $employee->id,
                    'department_id' => $position->department_id,
                    'position_id' => $position->id,
                    'position_name' => $position->name,
                    'date' => $date->toDateString(),
                    'status' => ChecklistStatus::Saved,
                    'note' => $input['note'],
                    'checked_by' => $input['checked_by'],
                    'filled_by' => $by->id,
                ]);
                $checklist->items()->createMany($this->tasksFor($employee, $position)->values()->map(fn (Task $task, int $i) => [
                    'task_id' => $task->id,
                    'section' => $task->section,
                    'title' => $task->title,
                    'standard' => $task->standard,
                    'sort' => $i + 1,
                    'status' => $input['items'][$task->id]['status'] ?? null,
                    'note' => $input['items'][$task->id]['note'] ?? null,
                ])->all());
                $this->replaceExtras($checklist, $input['extras']);

                return $checklist;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua simpanan bersamaan untuk staff + jabatan + tanggal yang sama: yang kedua dianggap sudah ada.
            throw new DuplicateChecklist($this->find($employee, $position, $date));
        }
    }

    /**
     * @param  array{items: array<int, array{status: ?string, note: ?string}>, note: ?string, checked_by: ?string, extras: list<array<string, mixed>>}  $input
     */
    public function update(Checklist $checklist, User $by, array $input): Checklist
    {
        return DB::transaction(function () use ($checklist, $by, $input) {
            foreach ($checklist->items as $item) {
                $item->update(['status' => $input['items'][$item->id]['status'] ?? $item->status, 'note' => $input['items'][$item->id]['note'] ?? null]);
            }
            $checklist->update(['note' => $input['note'], 'checked_by' => $input['checked_by'], 'filled_by' => $by->id]);
            $this->replaceExtras($checklist, $input['extras']);

            return $checklist;
        });
    }

    /** @param  list<array<string, mixed>>  $extras */
    private function replaceExtras(Checklist $checklist, array $extras): void
    {
        $checklist->extraTasks()->delete();
        $checklist->extraTasks()->createMany(array_map(fn ($extra, $i) => $extra + ['sort' => $i + 1], $extras, array_keys($extras)));
    }
}

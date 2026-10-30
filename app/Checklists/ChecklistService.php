<?php

namespace App\Checklists;

use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use App\Enums\Role;
use App\Audit\AuditLogger;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\TaskParameter;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            return $position->tasks()->where('is_active', true)->with('parameters')->get();
        }

        return Task::query()->select('tasks.*')
            ->join('positions', 'positions.id', '=', 'tasks.position_id')
            ->whereIn('tasks.id', $employee->assignedTasks()->select('tasks.id'))
            ->where('tasks.is_active', true)
            ->orderByRaw('tasks.position_id = ? desc', [$position->id])
            ->orderBy('positions.sort')->orderBy('positions.name')->orderBy('tasks.sort')->orderBy('tasks.id')
            ->with('parameters')
            ->get();
    }

    public function find(Employee $employee, Position $position, CarbonImmutable $date): ?Checklist
    {
        return Checklist::where('employee_id', $employee->id)->where('position_id', $position->id)->whereDate('date', $date)->first();
    }

    /** Membuka checklist staff + jabatan + tanggal itu, atau membuatnya (semua tugas belum diisi) bila belum ada. $checkedBy = nama pemeriksa (akun HOD / HR / Admin yang memulai); null untuk staff yang mengisi sendiri. */
    /** @param  list<int>|null  $taskIds  tugas yang dipilih (dari jabatan mana pun); null = tugas bawaan pegawai untuk jabatannya */
    public function start(Employee $employee, Position $position, CarbonImmutable $date, ?User $by, ?string $checkedBy, bool $assigned = false, ?array $taskIds = null): Checklist
    {
        try {
            return DB::transaction(function () use ($employee, $position, $date, $by, $checkedBy, $assigned, $taskIds) {
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
                    'filled_by' => $by?->id,
                    'assigned_at' => $assigned ? now() : null,
                ]);
                $tasks = ($taskIds === null
                    ? $this->tasksFor($employee, $position)
                    : $this->departmentTasks($position->department_id)->whereIn('id', $taskIds)->values())->values();
                $items = $checklist->items()->createMany($tasks->map(fn (Task $task, int $i) => [
                    'task_id' => $task->id,
                    'section' => $task->section,
                    'title' => $task->title,
                    'standard' => $task->standard,
                    'sort' => $i + 1,
                ])->all());
                foreach ($items as $i => $item) {
                    $item->parameters()->createMany($this->snapshotParameters($tasks[$i]));
                }

                return $checklist;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua perangkat memulai bersamaan: yang kedua membuka yang sudah dibuat.
            return $this->find($employee, $position, $date);
        }
    }

    /** Status tunggal (Done / Ongoing / Pending) untuk tugas yang punya parameter Status. Status kosong (null) berarti belum diisi. */
    public function saveItem(ChecklistItem $item, ?string $status, ?string $note, User $by): void
    {
        $statusParameter = $item->parameters()->where('type', ParameterRules::STATUS)->first();
        $this->saveParameters($item, $statusParameter ? [(string) $statusParameter->id => $status] : [], $note, $by);
    }

    /**
     * Menyimpan nilai parameter (hanya yang dikirim). Status tugas dihitung ulang dari semua parameternya.
     *
     * @param  array<string, ?string>  $values  id parameter => nilai
     *
     * @throws ValidationException
     */
    public function saveParameters(ChecklistItem $item, array $values, ?string $note, User $by): void
    {
        $parameters = $item->parameters()->get();
        $results = [];
        $errors = [];
        foreach ($parameters as $parameter) {
            if (! array_key_exists((string) $parameter->id, $values)) {
                continue;
            }
            $result = ParameterRules::evaluate($parameter, $values[(string) $parameter->id]);
            if ($result['error']) {
                $errors["parameters.{$parameter->id}"] = $result['error'];

                continue;
            }
            $results[$parameter->id] = $result['value'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($item, $parameters, $results, $note) {
            foreach ($parameters as $parameter) {
                if (array_key_exists($parameter->id, $results)) {
                    $parameter->update(['value' => $results[$parameter->id]]);
                }
            }
            $item->update([
                'status' => ParameterRules::status($item->parameters()->get())?->value,
                'note' => $this->clean($note),
            ]);
        });
        $this->touch($item->checklist, $by);
    }

    /**
     * Salinan parameter tugas untuk satu item. Tugas tanpa parameter tetap diberi Status (Done / Ongoing / Pending).
     *
     * @return list<array<string, mixed>>
     */
    private function snapshotParameters(Task $task): array
    {
        if ($task->parameters->isEmpty()) {
            return [['task_parameter_id' => null, 'sort' => 1, 'name' => 'Status', 'type' => ParameterRules::STATUS]];
        }

        return $task->parameters->values()->map(fn (TaskParameter $parameter, int $i) => [
            'task_parameter_id' => $parameter->id,
            'sort' => $i + 1,
            'name' => $parameter->name,
            'type' => $parameter->type,
            'unit' => $parameter->unit,
            'min_value' => $parameter->min_value,
            'max_value' => $parameter->max_value,
            'options' => $parameter->options,
            'normal_option' => $parameter->normal_option,
        ])->all();
    }

    /**
     * Catatan umum dan tugas tambahan; hanya kunci yang dikirim yang diubah.
     *
     * @param  array{note?: ?string, extras?: list<array{title?: ?string, status?: ?string, note?: ?string}>}  $meta
     */
    public function saveMeta(Checklist $checklist, User $by, array $meta): void
    {
        DB::transaction(function () use ($checklist, $by, $meta) {
            if (array_key_exists('note', $meta)) {
                $checklist->note = $this->clean($meta['note']);
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
        // Checklist yang dibuatkan sekaligus belum punya pemeriksa: atasan yang pertama mengisinya (bukan stafnya sendiri) tercatat sebagai pemeriksa.
        if ($checklist->assigned_at && $checklist->checked_by === null && $by->role !== Role::Staff && $by->employee_id !== $checklist->employee_id) {
            $checklist->checked_by = $by->name();
        }
        $checklist->forceFill(['filled_by' => $by->id])->save();
        $checklist->touch();
    }

    private function clean(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }

    /**
     * Tugas yang dikerjakan pada satu checklist hari itu. Pilihannya dari tugas default pegawai (Atur tugas).
     * Tugas yang belum diisi bisa dihapus dari checklist; yang sudah diisi (ada status, catatan, nilai, atau foto) tetap.
     * Tugas yang dipilih lagi ditambahkan di akhir. Perubahan dicatat di log aplikasi.
     *
     * @param  list<int|string>  $taskIds
     * @return array{added: int, removed: int, kept: int}
     */
    public function setTasks(Checklist $checklist, array $taskIds, User $by): array
    {
        $checklist->loadMissing(['employee', 'items.parameters', 'items.photos']);
        $position = Position::findOrFail($checklist->position_id);
        // Tugas bawaan pegawai, ditambah tugas jabatan lain di departemen yang sama (dikerjakan hari ini).
        $defaults = $this->tasksFor($checklist->employee, $position)->merge($this->departmentTasks($position->department_id))->unique('id')->keyBy('id');
        $wanted = collect($taskIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $defaults->has($id))->unique()->values();

        $added = 0;
        $removed = 0;
        $kept = 0;
        DB::transaction(function () use ($checklist, $defaults, $wanted, &$added, &$removed, &$kept) {
            foreach ($checklist->items as $item) {
                if ($wanted->contains($item->task_id)) {
                    continue;
                }
                if ($this->answered($item)) {
                    $kept++;

                    continue;
                }
                $item->delete();
                $removed++;
            }

            $present = $checklist->items()->pluck('task_id');
            $sort = (int) $checklist->items()->max('sort');
            foreach ($wanted as $taskId) {
                if ($present->contains($taskId)) {
                    continue;
                }
                $task = $defaults[$taskId];
                $item = $checklist->items()->create([
                    'task_id' => $task->id,
                    'section' => $task->section,
                    'title' => $task->title,
                    'standard' => $task->standard,
                    'sort' => ++$sort,
                ]);
                $item->parameters()->createMany($this->snapshotParameters($task));
                $added++;
            }
        });

        if ($added || $removed) {
            AuditLogger::record('checklist.tugas', 'Checklist', $checklist->id, $checklist->employee->name.' · '.$checklist->position_name, ['ditambah' => $added, 'dihapus' => $removed]);
            $this->touch($checklist, $by);
        }

        return ['added' => $added, 'removed' => $removed, 'kept' => $kept];
    }

    /** Ada satu pun tugas yang sudah diisi pada checklist ini. */
    public function hasAnswers(Checklist $checklist): bool
    {
        return $checklist->items->contains(fn (ChecklistItem $item) => $this->answered($item));
    }

    /** Sudah ada isian (status, catatan, nilai parameter, atau foto) sehingga tugas tidak boleh dihapus dari checklist. */
    public function answered(ChecklistItem $item): bool
    {
        return $item->status !== null
            || filled($item->note)
            || $item->parameters->contains(fn ($parameter) => filled($parameter->value))
            || $item->photos->isNotEmpty();
    }

    /**
     * Semua tugas aktif di departemen (lintas jabatan), dengan parameternya. Dipakai untuk tugas tambahan dari jabatan lain.
     *
     * @return Collection<int, Task>
     */
    public function departmentTasks(int $departmentId): Collection
    {
        return Task::query()->select('tasks.*')
            ->join('positions', 'positions.id', '=', 'tasks.position_id')
            ->where('positions.department_id', $departmentId)->where('positions.is_active', true)
            ->where('tasks.is_active', true)
            ->orderBy('positions.sort')->orderBy('positions.name')->orderBy('tasks.sort')->orderBy('tasks.id')
            ->with(['parameters', 'position'])
            ->get();
    }
}

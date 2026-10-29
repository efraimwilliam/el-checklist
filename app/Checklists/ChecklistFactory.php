<?php

namespace App\Checklists;

use App\Models\Checklist;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Membuat checklist harian pegawai dari templat tugas jabatannya (sekali per tanggal). */
class ChecklistFactory
{
    /** Null bila jabatan belum diatur atau pegawai tanpa departemen. */
    public function forDate(User $user, CarbonImmutable $date): ?Checklist
    {
        $employee = $user->employee;
        $existing = Checklist::where('employee_id', $employee->id)->whereDate('date', $date)->first();
        if ($existing) {
            return $existing;
        }

        $position = $user->position;
        $departmentId = $user->checklistDepartmentId();
        if (! $position || ! $departmentId) {
            return null;
        }

        return DB::transaction(function () use ($user, $employee, $position, $departmentId, $date) {
            $checklist = Checklist::create([
                'employee_id' => $employee->id,
                'department_id' => $departmentId,
                'position_id' => $position->id,
                'position_name' => $position->name,
                'date' => $date->toDateString(),
            ]);
            // Urutan item = urutan tugas (nomor urut tugas dari jabatan berbeda bisa bertabrakan, jadi dinomori ulang).
            $checklist->items()->createMany($this->tasksFor($user, $position)->values()->map(fn ($task, $i) => [
                'task_id' => $task->id,
                'section' => $task->section,
                'title' => $task->title,
                'standard' => $task->standard,
                'sort' => $i + 1,
            ])->all());

            return $checklist;
        });
    }

    /**
     * Tugas aktif untuk checklist baru: semua tugas jabatan (bawaan), atau hanya yang dipilih HOD, tugas jabatannya sendiri lebih dulu.
     *
     * @return Collection<int, Task>
     */
    private function tasksFor(User $user, Position $position): Collection
    {
        if (! $user->custom_tasks) {
            return $position->tasks()->where('is_active', true)->get();
        }

        return Task::query()->select('tasks.*')
            ->join('positions', 'positions.id', '=', 'tasks.position_id')
            ->whereIn('tasks.id', $user->assignedTasks()->select('tasks.id'))
            ->where('tasks.is_active', true)
            ->orderByRaw('tasks.position_id = ? desc', [$position->id])
            ->orderBy('positions.sort')->orderBy('positions.name')->orderBy('tasks.sort')->orderBy('tasks.id')
            ->get();
    }
}

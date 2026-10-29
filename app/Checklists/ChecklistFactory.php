<?php

namespace App\Checklists;

use App\Models\Checklist;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $departmentId = $employee->department_id ?? $position?->department_id;
        if (! $position || ! $departmentId) {
            return null;
        }

        return DB::transaction(function () use ($employee, $position, $departmentId, $date) {
            $checklist = Checklist::create([
                'employee_id' => $employee->id,
                'department_id' => $departmentId,
                'position_id' => $position->id,
                'position_name' => $position->name,
                'date' => $date->toDateString(),
            ]);
            $checklist->items()->createMany($position->tasks()->where('is_active', true)->get()->map(fn ($task) => [
                'task_id' => $task->id,
                'section' => $task->section,
                'title' => $task->title,
                'standard' => $task->standard,
                'sort' => $task->sort,
            ])->all());

            return $checklist;
        });
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * HOD (departemennya), HR dan Administrator memilih tugas apa saja yang masuk checklist seorang pegawai Absensi (tidak perlu punya akun).
 * Bawaan: semua tugas aktif jabatan yang dipilih saat mengisi. Setelah dipilih, hanya tugas terpilih yang dipakai (boleh dari jabatan lain
 * di departemen yang sama). Berlaku untuk checklist yang dibuat sesudahnya; checklist yang sudah ada tidak berubah.
 */
class TeamTaskController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-team-tasks');
        $allowed = $request->user()->accessibleDepartmentIds();

        $members = Employee::with('department')->withCount('assignedTasks')
            ->where('is_active', true)->whereNotNull('department_id')
            ->when($allowed !== null, fn ($q) => $q->whereIn('department_id', $allowed))
            ->get()
            ->filter(fn (Employee $employee) => Gate::allows('assign-tasks', $employee))
            ->sortBy([fn ($a, $b) => strcmp($a->department->name, $b->department->name), fn ($a, $b) => strcmp($a->name, $b->name)]);

        return view('team.index', ['membersByDepartment' => $members->groupBy(fn (Employee $employee) => $employee->department->name)]);
    }

    public function edit(Request $request, Employee $employee): View
    {
        Gate::authorize('assign-tasks', $employee);
        $employee->load('department');

        $groups = $this->positionsWithTasks($employee);
        $from = (int) $request->query('dari');
        $selected = $employee->custom_tasks && ! $from
            ? $employee->assignedTasks()->pluck('tasks.id')->all()
            : $groups->firstWhere('id', $from)?->tasks->pluck('id')->all() ?? [];

        return view('team.edit', ['member' => $employee, 'groups' => $groups, 'from' => $from, 'selected' => old('tasks', $selected)]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('assign-tasks', $employee);
        $ids = collect($request->validate([
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*' => ['integer'],
        ], ['tasks.required' => __('Pilih minimal satu tugas.'), 'tasks.min' => __('Pilih minimal satu tugas.')])['tasks'])->unique()->values();

        // Hanya tugas aktif dari jabatan di departemen pegawai ini.
        $valid = Task::whereIn('id', $ids)->where('is_active', true)
            ->whereHas('position', fn ($q) => $q->where('department_id', $employee->department_id))->pluck('id');
        if ($valid->count() !== $ids->count()) {
            throw ValidationException::withMessages(['tasks' => __('Ada tugas yang tidak termasuk departemen pegawai ini atau sudah nonaktif.')]);
        }

        DB::transaction(function () use ($employee, $valid) {
            $employee->assignedTasks()->sync($valid);
            $employee->update(['custom_tasks' => true]);
        });

        return redirect()->route('team.index')->with('status', __('Tugas :name disimpan (:n tugas). Berlaku untuk checklist berikutnya.', ['name' => $employee->name, 'n' => $valid->count()]));
    }

    public function reset(Employee $employee): RedirectResponse
    {
        Gate::authorize('assign-tasks', $employee);
        DB::transaction(function () use ($employee) {
            $employee->assignedTasks()->detach();
            $employee->update(['custom_tasks' => false]);
        });

        return redirect()->route('team.index')->with('status', __('Tugas :name dikembalikan ke bawaan jabatan.', ['name' => $employee->name]));
    }

    /** Jabatan di departemen pegawai beserta tugas aktifnya. */
    private function positionsWithTasks(Employee $employee)
    {
        return Position::where('department_id', $employee->department_id)->where('is_active', true)
            ->with(['tasks' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (Position $position) => $position->tasks->isNotEmpty())->values();
    }
}

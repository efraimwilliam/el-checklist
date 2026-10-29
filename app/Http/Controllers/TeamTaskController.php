<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * HOD (departemennya), HR dan Administrator memilih tugas apa saja yang masuk checklist harian seorang pegawai.
 * Bawaan: semua tugas aktif jabatannya. Setelah dipilih, hanya tugas terpilih yang dipakai (boleh dari jabatan lain di departemen yang sama).
 * Berlaku untuk checklist yang dibuat sesudahnya; checklist yang sudah ada tidak berubah.
 */
class TeamTaskController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-team-tasks');
        $actor = $request->user();
        $allowed = $actor->accessibleDepartmentIds();

        $members = User::with(['employee.department', 'position'])->withCount('assignedTasks')
            ->where('is_active', true)
            ->whereHas('employee', fn ($q) => $q->where('is_active', true)->whereNotNull('department_id')
                ->when($allowed !== null, fn ($q) => $q->whereIn('department_id', $allowed)))
            ->get()
            ->filter(fn (User $member) => Gate::allows('assign-tasks', $member))
            ->sortBy([fn ($a, $b) => strcmp($a->employee->department->name, $b->employee->department->name), fn ($a, $b) => strcmp($a->employee->name, $b->employee->name)]);

        return view('team.index', ['membersByDepartment' => $members->groupBy(fn (User $member) => $member->employee->department->name)]);
    }

    public function edit(User $user): View
    {
        Gate::authorize('assign-tasks', $user);
        $user->load(['employee.department', 'position']);

        $groups = $this->positionsWithTasks($user);
        $selected = $user->custom_tasks
            ? $user->assignedTasks()->pluck('tasks.id')->all()
            : $groups->firstWhere('id', $user->position_id)?->tasks->pluck('id')->all() ?? [];

        return view('team.edit', ['member' => $user, 'groups' => $groups, 'selected' => old('tasks', $selected)]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('assign-tasks', $user);
        $ids = collect($request->validate([
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*' => ['integer'],
        ], ['tasks.required' => __('Pilih minimal satu tugas.'), 'tasks.min' => __('Pilih minimal satu tugas.')])['tasks'])->unique()->values();

        // Hanya tugas aktif dari jabatan di departemen pegawai ini.
        $valid = Task::whereIn('id', $ids)->where('is_active', true)
            ->whereHas('position', fn ($q) => $q->where('department_id', $user->checklistDepartmentId()))->pluck('id');
        if ($valid->count() !== $ids->count()) {
            throw ValidationException::withMessages(['tasks' => __('Ada tugas yang tidak termasuk departemen pegawai ini atau sudah nonaktif.')]);
        }

        DB::transaction(function () use ($user, $valid) {
            $user->assignedTasks()->sync($valid);
            $user->update(['custom_tasks' => true]);
        });

        return redirect()->route('team.index')->with('status', __('Tugas :name disimpan (:n tugas). Berlaku untuk checklist berikutnya.', ['name' => $user->name(), 'n' => $valid->count()]));
    }

    public function reset(User $user): RedirectResponse
    {
        Gate::authorize('assign-tasks', $user);
        DB::transaction(function () use ($user) {
            $user->assignedTasks()->detach();
            $user->update(['custom_tasks' => false]);
        });

        return redirect()->route('team.index')->with('status', __('Tugas :name dikembalikan ke bawaan jabatan.', ['name' => $user->name()]));
    }

    /** Jabatan di departemen pegawai beserta tugas aktifnya; jabatan pegawai ini paling atas. */
    private function positionsWithTasks(User $user)
    {
        return Position::where('department_id', $user->checklistDepartmentId())
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $user->position_id))
            ->with(['tasks' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (Position $position) => $position->tasks->isNotEmpty())
            ->sortByDesc(fn (Position $position) => $position->id === $user->position_id)->values();
    }
}

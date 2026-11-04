<?php

namespace App\Http\Controllers;

use App\Checklists\ParameterRules;
use App\Tasks\TaskParameters;
use App\Models\Department;
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

        return view('team.index', [
            'membersByDepartment' => $members->groupBy(fn (Employee $employee) => $employee->department->name),
            // HOD dengan satu departemen langsung melihat daftar pegawainya (tanpa kartu departemen).
            'single' => $allowed !== null && count($allowed) === 1,
        ]);
    }

    /** Daftar tugas tiap jabatan di departemen yang boleh diakses. Menambah tugas: HOD (departemennya), HR, Administrator. */
    public function catalog(Request $request): View
    {
        Gate::authorize('manage-team-tasks');
        $allowed = $request->user()->accessibleDepartmentIds();

        $catalog = Department::where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('sort')->orderBy('name')
            ->with(['positions' => fn ($q) => $q->where('is_active', true)->orderBy('sort')->orderBy('name')])
            ->get();

        // Semua tugas dimuat sekali; sortir, cari, dan halaman dikerjakan di browser (tanpa memuat ulang).
        $tasks = Task::query()->select('tasks.*')
            ->join('positions', 'positions.id', '=', 'tasks.position_id')
            ->join('departments', 'departments.id', '=', 'positions.department_id')
            ->where('tasks.is_active', true)->where('positions.is_active', true)->where('departments.is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('positions.department_id', $allowed))
            ->with('position.department')
            ->orderBy('departments.name')->orderBy('positions.name')->orderBy('tasks.sort')->orderBy('tasks.id')
            ->get();

        $sections = Task::query()->where('is_active', true)->whereNotNull('section')->distinct()->orderBy('section')->pluck('section');

        return view('team.catalog', [
            'catalog' => $catalog,
            'tasks' => $tasks,
            'sections' => $sections,
        ]);
    }

    /** HOD (departemennya), HR dan Administrator menambah tugas ke jabatan. Mengubah / menghapus / mengurutkan tetap di Kelola tugas. */
    public function storeTask(Request $request): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $data = $request->validate(['position' => ['required', 'integer', 'exists:positions,id']] + $this->taskRules());
        $position = Position::findOrFail($data['position']);
        abort_unless($position->is_active && $request->user()->canAccessDepartment($position->department_id), 403);

        $fields = $this->taskFields($data);
        $task = $position->tasks()->create($fields + ['sort' => (int) $position->tasks()->max('sort') + 1]);
        TaskParameters::sync($task, TaskParameters::rows($data));

        return redirect()->route('team.catalog')->withFragment('jabatan-'.$position->id)->with('status', __('Tugas ":title" ditambahkan ke :position.', ['title' => $fields['title'], 'position' => $position->name]));
    }

    /** Ubah tugas dari Daftar tugas (HOD untuk departemennya, HR, Administrator). Error dikirim ke bag "editTask" supaya popup ubah yang dibuka lagi. */
    public function updateCatalogTask(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $task->loadMissing('position');
        abort_unless($task->position->is_active && $request->user()->canAccessDepartment($task->position->department_id), 403);

        $data = $request->validateWithBag('editTask', ['position' => ['required', 'integer', 'exists:positions,id']] + $this->taskRules());
        $position = Position::findOrFail($data['position']);
        abort_unless($position->is_active && $request->user()->canAccessDepartment($position->department_id), 403);

        // Pindah jabatan hanya ke jabatan aktif yang masih boleh diakses; checklist lama tetap memakai salinan isinya.
        $task->update($this->taskFields($data) + ['position_id' => $position->id]);
        TaskParameters::sync($task, TaskParameters::rows($data, 'editTask'));

        return redirect()->route('team.catalog')->withFragment('jabatan-'.$position->id)->with('status', __('Tugas diperbarui.'));
    }

    /** Hapus tugas dari Daftar tugas. Checklist yang sudah dibuat menyalin isi tugas, jadi riwayatnya tidak berubah. */
    public function destroyCatalogTask(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $task->loadMissing('position');
        abort_unless($task->position->is_active && $request->user()->canAccessDepartment($task->position->department_id), 403);

        $task->delete();

        return redirect()->route('team.catalog')->withFragment('jabatan-'.$task->position_id)->with('status', __('Tugas dihapus. Checklist yang sudah dibuat tidak berubah.'));
    }

    /** @return array<string, array<int, string>> */
    private function taskRules(): array
    {
        return [
            'section' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:500'],
            'standard' => ['nullable', 'string', 'max:2000'],
        ] + ParameterRules::editorRules();
    }

    /** @return array{section: ?string, title: string, standard: ?string} */
    private function taskFields(array $data): array
    {
        return array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), collect($data)->only(['section', 'title', 'standard'])->all());
    }

    public function edit(Request $request, Employee $employee): View
    {
        Gate::authorize('assign-tasks', $employee);
        $employee->load('department');

        $groups = $this->positionsWithTasks($employee);
        $selected = $employee->custom_tasks ? $employee->assignedTasks()->pluck('tasks.id')->all() : [];

        return view('team.edit', ['member' => $employee, 'groups' => $groups, 'selected' => old('tasks', $selected)]);
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

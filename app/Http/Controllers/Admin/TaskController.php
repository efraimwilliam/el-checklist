<?php

namespace App\Http\Controllers\Admin;

use App\Checklists\ParameterRules;
use App\Tasks\TaskParameters;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Position;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administrator / HR mengelola jabatan dan daftar tugasnya. Perubahan hanya berlaku untuk checklist yang dibuat sesudahnya:
 * checklist yang sudah ada menyalin isi tugas saat dibuat, jadi riwayat tidak berubah (termasuk bila tugas dihapus).
 */
class TaskController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-tasks');

        return view('admin.tasks.index', [
            'departments' => Department::with(['positions' => fn ($q) => $q->withCount([
                'tasks as active_tasks_count' => fn ($q) => $q->where('is_active', true),
                'checklists',
            ])])->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function show(Position $position): View
    {
        Gate::authorize('manage-tasks');

        return view('admin.tasks.show', [
            'position' => $position->load(['department', 'tasks']),
            'catalog' => $this->catalog($position),
        ]);
    }

    public function storePosition(Request $request): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('positions')->where('department_id', $request->input('department_id'))],
        ], ['name.unique' => __('Jabatan dengan nama itu sudah ada di departemen ini.')]);

        $position = Position::create($data + ['sort' => (int) Position::where('department_id', $data['department_id'])->max('sort') + 1]);

        return redirect()->route('admin.tasks.show', $position)->with('status', __('Jabatan ditambahkan. Tambahkan tugasnya di bawah.'));
    }

    public function updatePosition(Request $request, Position $position): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('positions')->where('department_id', $position->department_id)->ignore($position)],
        ], ['name.unique' => __('Jabatan dengan nama itu sudah ada di departemen ini.')]);

        $position->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', __('Jabatan disimpan.'));
    }

    /**
     * Hapus jabatan beserta tugasnya. Hanya bila belum pernah dipakai checklist (riwayat tetap utuh);
     * jabatan yang sudah dipakai cukup dinonaktifkan. Pegawai yang memakai jabatan ini dilepas dari jabatan checklist-nya.
     */
    public function destroyPosition(Position $position): RedirectResponse
    {
        Gate::authorize('manage-tasks');

        if ($position->checklists()->exists()) {
            return back()->withErrors(['name' => __('Jabatan ":name" sudah dipakai checklist, jadi tidak bisa dihapus. Nonaktifkan saja.', ['name' => $position->name])]);
        }

        $name = $position->name;
        $position->delete();

        return redirect()->route('admin.tasks.index')->with('status', __('Jabatan ":name" dihapus.', ['name' => $name]));
    }

    /**
     * Salin tugas yang dipilih dari kamus (jabatan lain, di departemen mana pun) ke jabatan ini.
     * Tugas yang judulnya sudah ada di jabatan ini dilewati. Isi, standar, dan parameternya ikut disalin.
     */
    public function importFromCatalog(Request $request, Position $position): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:300'],
            'tasks.*' => ['integer', 'exists:tasks,id'],
        ], ['tasks.required' => __('Pilih minimal satu tugas dari kamus.')]);

        $sources = Task::with('parameters')->whereIn('id', $data['tasks'])->orderBy('id')->get();
        $titles = $position->tasks()->pluck('title')->map(fn (string $title) => mb_strtolower($title))->all();
        $sort = (int) $position->tasks()->max('sort');
        $added = 0;
        $skipped = 0;

        foreach ($sources as $source) {
            if (in_array(mb_strtolower($source->title), $titles, true)) {
                $skipped++;

                continue;
            }
            $task = $position->tasks()->create([
                'section' => $source->section,
                'title' => $source->title,
                'standard' => $source->standard,
                'remarks' => $source->remarks,
                'sort' => ++$sort,
            ]);
            TaskParameters::sync($task, $source->parameters->map(fn ($p) => $p->only(['name', 'type', 'unit', 'min_value', 'max_value', 'options', 'normal_option']))->values()->all());
            $titles[] = mb_strtolower($source->title);
            $added++;
        }

        $message = __(':count tugas ditambahkan dari kamus.', ['count' => $added]);
        if ($skipped) {
            $message .= ' '.__(':count sudah ada di jabatan ini dan dilewati.', ['count' => $skipped]);
        }

        return redirect()->route('admin.tasks.show', $position)->with('status', $message);
    }

    /** Tugas aktif dari semua jabatan aktif (kecuali jabatan ini), untuk dipilih ke jabatan baru. */
    private function catalog(Position $position)
    {
        return Task::with('position.department')
            ->where('tasks.is_active', true)->where('tasks.position_id', '!=', $position->id)
            ->whereHas('position', fn ($q) => $q->where('is_active', true)->whereHas('department', fn ($d) => $d->where('is_active', true)))
            ->join('positions', 'positions.id', '=', 'tasks.position_id')
            ->join('departments', 'departments.id', '=', 'positions.department_id')
            ->orderBy('departments.sort')->orderBy('departments.name')->orderBy('positions.name')->orderBy('tasks.sort')
            ->select('tasks.*')->get();
    }

    public function storeTask(Request $request, Position $position): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate($this->rules());
        $task = $position->tasks()->create($this->fields($data) + ['sort' => (int) $position->tasks()->max('sort') + 1]);
        TaskParameters::sync($task, TaskParameters::rows($data));

        return back()->with('status', __('Tugas ditambahkan.'));
    }

    public function updateTask(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $data = $request->validate($this->rules());
        $task->update($this->fields($data) + ['is_active' => $request->boolean('is_active')]);
        TaskParameters::sync($task, TaskParameters::rows($data));

        return redirect()->route('admin.tasks.show', $task->position_id)->with('status', __('Tugas disimpan.'));
    }

    public function destroyTask(Task $task): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $task->delete();

        return redirect()->route('admin.tasks.show', $task->position_id)->with('status', __('Tugas dihapus. Checklist yang sudah dibuat tidak berubah.'));
    }

    public function moveTask(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        Gate::authorize('manage-tasks');
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        // Urutan dirapikan jadi 1..n dulu (data impor bisa punya nomor ganda), baru dua tugas bertetangga ditukar.
        $ids = Task::where('position_id', $task->position_id)->orderBy('sort')->orderBy('id')->pluck('id')->all();
        $at = array_search($task->id, $ids, true);
        $swapWith = $direction === 'up' ? $at - 1 : $at + 1;
        if (isset($ids[$swapWith])) {
            [$ids[$at], $ids[$swapWith]] = [$ids[$swapWith], $ids[$at]];
        }
        foreach ($ids as $i => $id) {
            Task::whereKey($id)->update(['sort' => $i + 1]);
        }

        // Dari halaman (JavaScript) cukup jawaban singkat: urutan sudah tersimpan, tanpa memuat ulang halaman.
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'order' => $ids]);
        }

        return redirect()->route('admin.tasks.show', $task->position_id);
    }

    /** @return array{section: ?string, title: string, standard: ?string, remarks: ?string} */
    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'section' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:500'],
            'standard' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ] + ParameterRules::editorRules();
    }

    /** @param  array<string, mixed>  $data */
    private function fields(array $data): array
    {
        return array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), collect($data)->only(['section', 'title', 'standard', 'remarks'])->all());
    }
}

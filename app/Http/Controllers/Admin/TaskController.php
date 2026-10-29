<?php

namespace App\Http\Controllers\Admin;

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

        return view('admin.tasks.show', ['position' => $position->load(['department', 'tasks'])]);
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

    public function storeTask(Request $request, Position $position): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $position->tasks()->create($this->validated($request) + ['sort' => (int) $position->tasks()->max('sort') + 1]);

        return back()->with('status', __('Tugas ditambahkan.'));
    }

    public function updateTask(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('manage-tasks');
        $task->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

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
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'section' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:500'],
            'standard' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $data);
    }
}

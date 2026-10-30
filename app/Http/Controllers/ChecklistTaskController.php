<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistService;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Position;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Atur tugas hari ini untuk satu checklist: centang tugas yang dikerjakan (HOD, HR, Administrator). */
class ChecklistTaskController extends Controller
{
    public function edit(Request $request, Checklist $checklist, ChecklistService $service): View
    {
        $this->authorizeEdit($request, $checklist);
        $checklist->loadMissing(['employee', 'items.parameters', 'items.photos']);
        $position = Position::findOrFail($checklist->position_id);
        $items = $checklist->items->whereNotNull('task_id')->keyBy('task_id');

        // Tugas default pegawai, ditambah tugas yang sudah ada di checklist (mis. default-nya sudah diubah).
        $tasks = $service->tasksFor($checklist->employee, $position)->keyBy('id');
        foreach ($items as $taskId => $item) {
            if (! $tasks->has($taskId)) {
                $tasks->put($taskId, Task::find($taskId));
            }
        }

        $rows = $tasks->filter()->values()->map(fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'section' => $task->section ?: null,
            'checked' => $items->has($task->id),
            'locked' => $items->has($task->id) && $service->answered($items[$task->id]),
        ])->sortBy(fn ($row) => $row['section'] ?? '')->groupBy(fn ($row) => $row['section'] ?? '');

        // Tugas dari jabatan lain di departemen yang sama, dikelompokkan per jabatan (dilipat).
        $shown = $rows->flatten(1)->pluck('id')->all();
        $others = $service->departmentTasks($position->department_id)
            ->reject(fn (Task $task) => in_array($task->id, $shown, true))
            ->groupBy(fn (Task $task) => $task->position->name)
            ->map(fn ($tasks) => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'section' => $task->section ?: null,
                'checked' => $items->has($task->id),
                'locked' => $items->has($task->id) && $service->answered($items[$task->id]),
            ])->values());

        return view('checklists.tasks', ['checklist' => $checklist, 'groups' => $rows, 'others' => $others]);
    }

    public function update(Request $request, Checklist $checklist, ChecklistService $service): RedirectResponse
    {
        $this->authorizeEdit($request, $checklist);
        $data = $request->validate(['tasks' => ['nullable', 'array'], 'tasks.*' => ['integer']]);

        $result = $service->setTasks($checklist, $data['tasks'] ?? [], $request->user());

        return redirect()->route('checklists.fill', $checklist)->with('status', __('Tugas hari ini disimpan: :added ditambah, :removed dihapus, :kept tetap karena sudah diisi.', $result));
    }

    private function authorizeEdit(Request $request, Checklist $checklist): void
    {
        abort_if($request->user()->role === Role::Staff, 403);
        Gate::authorize('plan', $checklist);
    }
}

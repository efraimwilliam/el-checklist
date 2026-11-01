<?php

namespace App\Http\Controllers;

use App\Checklists\PositionSuggester;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Jabatan checklist tiap pegawai, diatur sekali oleh HOD (HR / Administrator semua departemen), supaya checklist harian bisa dibuatkan sekaligus. */
class TeamPositionController extends Controller
{
    public function edit(Request $request, PositionSuggester $suggester): View
    {
        Gate::authorize('manage-team-tasks');
        $allowed = $request->user()->accessibleDepartmentIds();

        $employees = Employee::with(['department', 'checklistPosition'])->where('is_active', true)->whereNotNull('department_id')
            ->when($allowed !== null, fn ($q) => $q->whereIn('department_id', $allowed))->orderBy('name')->get()
            ->filter(fn (Employee $employee) => Gate::allows('assign-tasks', $employee));
        $positions = Position::where('is_active', true)->whereIn('department_id', $employees->pluck('department_id')->unique())
            ->whereHas('tasks', fn ($q) => $q->where('is_active', true))->orderBy('sort')->orderBy('name')->get()->groupBy('department_id');

        // Pegawai yang jabatan Absensi-nya berubah (atau jabatan checklist-nya bukan dari departemennya lagi) ditandai; HOD yang memutuskan.
        $reviews = $employees->mapWithKeys(fn (Employee $e) => [$e->id => $e->positionReview()])->filter();

        $suggestions = $employees->filter(fn (Employee $e) => ! $e->checklist_position_id || $reviews->has($e->id))
            ->mapWithKeys(fn (Employee $e) => [$e->id => $suggester->suggest($e, $positions->get($e->department_id, collect()))?->id])
            ->filter()->reject(fn (int $positionId, int $employeeId) => $positionId === $employees->firstWhere('id', $employeeId)->checklist_position_id);

        return view('team.positions', [
            'byDepartment' => $employees->sortBy([fn ($a, $b) => strcmp($a->department->name, $b->department->name)])->groupBy(fn (Employee $e) => $e->department->name),
            'positions' => $positions,
            'suggestions' => $suggestions,
            'reviews' => $reviews,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $data = $request->validate([
            'positions' => ['required', 'array'], 'positions.*' => ['nullable', 'integer'],
            'confirm' => ['array'], 'confirm.*' => ['integer'],
        ]);
        $confirmed = array_map('intval', $data['confirm'] ?? []);

        $changed = 0;
        foreach ($data['positions'] as $employeeId => $positionId) {
            $employee = Employee::findOrFail($employeeId);
            Gate::authorize('assign-tasks', $employee);
            if ($positionId !== null && ! Position::where('id', $positionId)->where('is_active', true)->where('department_id', $employee->department_id)->exists()) {
                throw ValidationException::withMessages(["positions.{$employeeId}" => __('Jabatan harus dari departemen :name.', ['name' => $employee->department->name])]);
            }
            $new = $positionId === null ? null : (int) $positionId;
            $update = [];
            if ($employee->checklist_position_id !== $new) {
                $update = ['checklist_position_id' => $new, 'checklist_title' => $new ? $employee->position_title : null];
                $changed++;
            } elseif ($new && in_array($employee->id, $confirmed, true)) {
                // Jabatan checklist dinyatakan tetap benar: teks Absensi yang sekarang jadi acuan baru.
                $update = ['checklist_title' => $employee->position_title];
            }
            $update && $employee->update($update);
        }

        return redirect()->route('team.positions.edit')->with('status', __('Jabatan checklist disimpan (:n diubah).', ['n' => $changed]));
    }
}

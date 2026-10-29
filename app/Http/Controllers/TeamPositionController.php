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

        $suggestions = $employees->reject(fn (Employee $e) => $e->checklist_position_id)
            ->mapWithKeys(fn (Employee $e) => [$e->id => $suggester->suggest($e, $positions->get($e->department_id, collect()))?->id])->filter();

        return view('team.positions', [
            'byDepartment' => $employees->sortBy([fn ($a, $b) => strcmp($a->department->name, $b->department->name)])->groupBy(fn (Employee $e) => $e->department->name),
            'positions' => $positions,
            'suggestions' => $suggestions,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $input = $request->validate(['positions' => ['required', 'array'], 'positions.*' => ['nullable', 'integer']])['positions'];

        $changed = 0;
        foreach ($input as $employeeId => $positionId) {
            $employee = Employee::findOrFail($employeeId);
            Gate::authorize('assign-tasks', $employee);
            if ($positionId !== null && ! Position::where('id', $positionId)->where('is_active', true)->where('department_id', $employee->department_id)->exists()) {
                throw ValidationException::withMessages(["positions.{$employeeId}" => __('Jabatan harus dari departemen :name.', ['name' => $employee->department->name])]);
            }
            if ($employee->checklist_position_id !== ($positionId === null ? null : (int) $positionId)) {
                $employee->update(['checklist_position_id' => $positionId]);
                $changed++;
            }
        }

        return redirect()->route('team.positions.edit')->with('status', __('Jabatan checklist disimpan (:n diubah).', ['n' => $changed]));
    }
}

<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistInput;
use App\Checklists\ChecklistService;
use App\Enums\ChecklistStatus;
use App\Models\Checklist;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChecklistController extends Controller
{
    /** Daftar checklist per tanggal, dibatasi departemen yang boleh diakses. */
    public function index(Request $request): View
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        $allowed = $user->accessibleDepartmentIds();
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ChecklistStatus::class)],
        ]);
        $date = CarbonImmutable::parse($filters['date'] ?? 'today');
        $departmentId = isset($filters['department']) ? (int) $filters['department'] : null;
        abort_if($departmentId && ! $user->canAccessDepartment($departmentId), 403);

        $checklists = Checklist::with(['employee', 'department', 'items'])
            ->whereDate('date', $date)
            ->when($allowed !== null, fn ($q) => $q->whereIn('department_id', $allowed))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('department_id')->orderBy('position_name')->orderBy('id')
            ->get();

        return view('checklists.index', [
            'checklists' => $checklists,
            'date' => $date,
            'departments' => Department::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort')->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, Checklist $checklist): View
    {
        Gate::authorize('view', $checklist);
        $checklist->load(['items', 'extraTasks', 'employee', 'department', 'filler.employee', 'reviewer.employee']);
        $user = $request->user();

        return view('checklists.show', [
            'checklist' => $checklist,
            'canEdit' => $user->can('update', $checklist) && $checklist->position_id !== null,
            'canReview' => $user->can('review', $checklist),
            'canDelete' => $user->can('delete', $checklist),
        ]);
    }

    /** Memperbarui checklist yang sudah ada (dari halaman isi). */
    public function update(Request $request, Checklist $checklist, ChecklistService $service): RedirectResponse
    {
        Gate::authorize('update', $checklist);
        $input = ChecklistInput::validate($request, $checklist->items()->pluck('id')->all());
        $service->update($checklist->load('items'), $request->user(), $input);

        return redirect()->route('checklists.show', $checklist)->with('status', __('Checklist diperbarui.'));
    }

    /** "Tandai sudah direview": nama dan waktu reviewer tercatat, lalu isian terkunci untuk HOD. */
    public function review(Request $request, Checklist $checklist): RedirectResponse
    {
        Gate::authorize('review', $checklist);
        $checklist->update(['status' => ChecklistStatus::Reviewed, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return redirect()->route('checklists.show', $checklist)->with('status', __('Checklist ditandai sudah direview.'));
    }

    public function destroy(Checklist $checklist): RedirectResponse
    {
        Gate::authorize('delete', $checklist);
        $checklist->delete();

        return redirect()->route('checklists.index', ['date' => $checklist->date->toDateString()])->with('status', __('Checklist dihapus.'));
    }
}

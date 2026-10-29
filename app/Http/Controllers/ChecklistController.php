<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistService;
use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
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
            'canEdit' => $user->can('update', $checklist),
            'canReview' => $user->can('review', $checklist),
            'canDelete' => $user->can('delete', $checklist),
        ]);
    }

    /** Halaman kartu / daftar: setiap pilihan tersimpan otomatis. Yang tidak boleh mengubah diarahkan ke detail. */
    public function fill(Request $request, Checklist $checklist, ChecklistService $service): View|RedirectResponse
    {
        Gate::authorize('view', $checklist);
        if (! $request->user()->can('update', $checklist)) {
            return redirect()->route('checklists.show', $checklist)->with('status', $checklist->isReviewed()
                ? __('Checklist ini sudah direview dan terkunci. Perubahan hanya lewat HR.')
                : __('Anda tidak bisa mengubah checklist ini.'));
        }
        $checklist->load(['items', 'extraTasks', 'employee', 'department']);

        return view('checklists.work', [
            'checklist' => $checklist,
            'staffMode' => $request->user()->role === Role::Staff,
            'statuses' => ItemStatus::cases(),
            'progress' => $service->progress($checklist),
        ]);
    }

    /** Penyimpanan otomatis catatan umum, "Dicek oleh" dan tugas tambahan (hanya yang dikirim). */
    public function meta(Request $request, Checklist $checklist, ChecklistService $service): JsonResponse
    {
        Gate::authorize('update', $checklist);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'checked_by' => ['nullable', 'string', 'max:255'],
            'extras' => ['array', 'max:20'],
            'extras.*.title' => ['nullable', 'string', 'max:500'],
            'extras.*.status' => ['nullable', Rule::enum(ItemStatus::class)],
            'extras.*.note' => ['nullable', 'string', 'max:1000'],
        ]);
        // Hanya kunci yang benar-benar dikirim yang diubah (validasi nullable bisa menambahkan kunci kosong).
        $service->saveMeta($checklist, $request->user(), array_intersect_key($data, $request->all()));

        return response()->json(['ok' => true, 'saved_at' => now()->format('H:i:s'), 'progress' => $service->progress($checklist)]);
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

<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistPhotoService;
use App\Checklists\ChecklistService;
use App\Checklists\ChecklistSettings;
use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use App\Enums\Role;
use App\Models\Checklist;
use App\Reports\ExcelReport;
use App\Support\DateRange;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChecklistController extends Controller
{
    /** Daftar checklist per tanggal, dibatasi departemen yang boleh diakses. */
    /** Ekspor Excel Riwayat: filter yang sama dengan halaman Riwayat (rentang, departemen, status). */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        $allowed = $user->accessibleDepartmentIds();
        $filters = $request->validate([
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ChecklistStatus::class)],
        ]);
        [$from, $to] = DateRange::fromRequest($request, 1, 92);
        $departmentId = isset($filters['department']) ? (int) $filters['department'] : null;
        abort_if($departmentId && ! $user->canAccessDepartment($departmentId), 403);

        $checklists = Checklist::with(['employee', 'department', 'items'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($allowed !== null, fn ($q) => $q->whereIn('department_id', $allowed))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('date')->orderBy('department_id')->orderBy('position_name')->orderBy('id')
            ->get();

        return ExcelReport::download("riwayat-{$from->toDateString()}-sampai-{$to->toDateString()}.xlsx", [ExcelReport::checklistSheet($checklists, 'Riwayat')]);
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        // Staff hanya melihat checklist miliknya sendiri (Riwayat dari menu header).
        $own = $user->role === Role::Staff;
        abort_unless($own || Gate::allows('view-checklists'), 403);
        $allowed = $user->accessibleDepartmentIds();
        $filters = $request->validate([
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ChecklistStatus::class)],
        ]);
        [$from, $to] = DateRange::fromRequest($request, 1, 92);
        $departmentId = isset($filters['department']) ? (int) $filters['department'] : null;
        abort_if($departmentId && ! $user->canAccessDepartment($departmentId), 403);

        $checklists = Checklist::with(['employee', 'department', 'items.photos'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($own, fn ($q) => $q->where('employee_id', $user->employee_id))
            ->when(! $own && $allowed !== null, fn ($q) => $q->whereIn('department_id', $allowed))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('date')->orderBy('department_id')->orderBy('position_name')->orderBy('id')
            ->get();

        return view('checklists.index', [
            'checklists' => $checklists,
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::today(),
            'departments' => Department::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort')->orderBy('name')->get(),
            'filters' => $filters,
            'own' => $own,
        ]);
    }

    public function show(Request $request, Checklist $checklist): View
    {
        Gate::authorize('view', $checklist);
        $checklist->load(['items.photos', 'extraTasks', 'employee', 'department', 'filler.employee', 'reviewer.employee']);
        $user = $request->user();

        return view('checklists.show', [
            'checklist' => $checklist,
            'canEdit' => $user->can('update', $checklist),
            'canReview' => $user->can('review', $checklist),
            'canUnreview' => $user->can('unreview', $checklist),
            'canDelete' => $user->can('delete', $checklist),
        ] + \App\Models\ChatThread::shareTargets($user));
    }

    /** Halaman kartu / daftar: setiap pilihan tersimpan otomatis. Yang tidak boleh mengubah diarahkan ke detail. */
    public function fill(Request $request, Checklist $checklist, ChecklistService $service): View|RedirectResponse
    {
        Gate::authorize('view', $checklist);
        if (! $request->user()->can('update', $checklist)) {
            return redirect()->route('checklists.show', $checklist)->with('status', match (true) {
                $checklist->isReviewed() => __('Checklist ini sudah direview GM/HR dan terkunci. Perubahan hanya lewat HR.'),
                $checklist->isHodReviewed() => __('Checklist ini sudah direview HOD. Batalkan review dulu untuk mengedit.'),
                default => __('Anda tidak bisa mengubah checklist ini.'),
            });
        }
        $checklist->load(['items.photos', 'extraTasks', 'employee', 'department']);

        return view('checklists.work', [
            'photoRequirement' => app(ChecklistSettings::class)->photoRequirement(),
            'photoMax' => app(ChecklistSettings::class)->photosPerTask(),
            'checklist' => $checklist,
            'staffMode' => $request->user()->role === Role::Staff,
            'statuses' => ItemStatus::cases(),
            'progress' => $service->progress($checklist),
        ]);
    }

    /** Penyimpanan otomatis catatan umum dan tugas tambahan (hanya yang dikirim). */
    public function meta(Request $request, Checklist $checklist, ChecklistService $service): JsonResponse
    {
        Gate::authorize('update', $checklist);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
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
    /** HOD membatalkan verifikasinya: status kembali "Belum direview" dan isian bisa diubah lagi. */
    public function unreview(Checklist $checklist): RedirectResponse
    {
        Gate::authorize('unreview', $checklist);
        $checklist->update(['status' => ChecklistStatus::Saved, 'reviewed_by' => null, 'reviewed_at' => null]);

        return redirect()->route('checklists.show', $checklist)->with('status', __('Review dibatalkan. Checklist bisa diubah lagi.'));
    }

    public function review(Request $request, Checklist $checklist): RedirectResponse
    {
        Gate::authorize('review', $checklist);
        $final = $request->user()->role->reviews();
        $checklist->update([
            'status' => $final ? ChecklistStatus::Reviewed : ChecklistStatus::HodReviewed,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->route('checklists.show', $checklist)->with('status', $final
            ? __('Checklist ditandai direview oleh GM/HR dan terkunci.')
            : __('Checklist ditandai direview oleh HOD. GM atau HR masih bisa mereview.'));
    }

    public function destroy(Checklist $checklist, ChecklistPhotoService $photos): RedirectResponse
    {
        Gate::authorize('delete', $checklist);
        // Berkas foto ada di disk, bukan di database: dihapus lebih dulu supaya tidak tertinggal.
        $checklist->items()->with('photos')->get()->flatMap->photos->each(fn ($photo) => $photos->delete($photo));
        $checklist->delete();

        return redirect()->route('checklists.index', ['date' => $checklist->date->toDateString()])->with('status', __('Checklist dihapus.'));
    }
}

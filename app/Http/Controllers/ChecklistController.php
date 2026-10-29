<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistFactory;
use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use App\Models\Checklist;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChecklistController extends Controller
{
    /** Checklist hari ini milik pengguna (dibuat dari templat jabatannya bila belum ada). */
    public function today(Request $request, ChecklistFactory $factory): View|RedirectResponse
    {
        $user = $request->user();
        $checklist = $factory->forDate($user, CarbonImmutable::today());
        $history = Checklist::where('employee_id', $user->employee_id)->latest('date')->limit(14)->get();

        return $checklist
            ? redirect()->route('checklists.show', $checklist)
            : view('checklists.no-position', ['history' => $history]);
    }

    /** Daftar untuk direview, dibatasi departemen yang boleh diakses. */
    public function index(Request $request): View
    {
        Gate::authorize('review-checklists');
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
            ->orderBy('department_id')->orderBy('position_name')
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
        $checklist->load(['items', 'employee', 'department', 'reviewer.employee']);

        return view('checklists.show', [
            'checklist' => $checklist,
            'canEdit' => $request->user()->can('update', $checklist),
            'canReview' => $request->user()->can('review', $checklist),
            'statuses' => ItemStatus::cases(),
        ]);
    }

    /** Simpan isian; "submit" mengirim ke HOD (semua tugas wajib berstatus, Pending wajib bercatatan). */
    public function update(Request $request, Checklist $checklist): RedirectResponse
    {
        Gate::authorize('update', $checklist);
        $itemIds = $checklist->items()->pluck('id')->all();
        $data = $request->validate([
            'items' => ['array'],
            'items.*.status' => ['nullable', Rule::enum(ItemStatus::class)],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'action' => ['required', Rule::in(['save', 'submit'])],
        ]);
        $input = collect($data['items'] ?? [])->only($itemIds);

        if ($data['action'] === 'submit') {
            $errors = [];
            foreach ($itemIds as $id) {
                $status = $input[$id]['status'] ?? null;
                if (! $status) {
                    $errors["items.{$id}.status"] = __('Pilih status tugas ini.');
                } elseif ($status === ItemStatus::Pending->value && config('checklist.pending_note_required') && blank($input[$id]['note'] ?? null)) {
                    $errors["items.{$id}.note"] = __('Tugas Pending wajib diberi alasan.');
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        DB::transaction(function () use ($checklist, $input, $data) {
            foreach ($checklist->items as $item) {
                $item->update(['status' => $input[$item->id]['status'] ?? null, 'note' => $input[$item->id]['note'] ?? null]);
            }
            $checklist->note = $data['note'] ?? null;
            if ($data['action'] === 'submit') {
                $checklist->fill(['status' => ChecklistStatus::Submitted, 'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null]);
            }
            $checklist->save();
        });

        return redirect()->route('checklists.show', $checklist)->with('status', $data['action'] === 'submit'
            ? __('Checklist dikirim untuk direview.')
            : __('Checklist disimpan.'));
    }

    public function review(Request $request, Checklist $checklist): RedirectResponse
    {
        Gate::authorize('review', $checklist);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'review_note' => ['nullable', 'string', 'max:2000', 'required_if:decision,return'],
        ], ['review_note.required_if' => __('Tulis alasan checklist dikembalikan.')]);

        $checklist->update([
            'status' => $data['decision'] === 'approve' ? ChecklistStatus::Approved : ChecklistStatus::Returned,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        return redirect()->route('checklists.show', $checklist)->with('status', $data['decision'] === 'approve'
            ? __('Checklist disetujui.')
            : __('Checklist dikembalikan ke pegawai.'));
    }
}

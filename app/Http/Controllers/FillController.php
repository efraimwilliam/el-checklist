<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistInput;
use App\Checklists\ChecklistService;
use App\Checklists\DuplicateChecklist;
use App\Enums\ItemStatus;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

/**
 * Mengisi checklist harian: pilih jabatan, tanggal (boleh mundur, tidak ke depan) dan staff dari data Absensi, lalu tandai setiap tugas.
 * HOD mengisi untuk staff di departemennya; HR / Administrator untuk semua departemen; staff berakun hanya untuk dirinya sendiri.
 * Mengisi ulang staff + jabatan + tanggal yang sama memperbarui checklist lama, tidak membuat ganda.
 */
class FillController extends Controller
{
    public function __construct(private ChecklistService $service) {}

    public function today(): RedirectResponse
    {
        return redirect()->route('fill.create');
    }

    public function create(Request $request): View
    {
        Gate::authorize('fill-checklists');
        $user = $request->user();
        $today = CarbonImmutable::today();
        $date = $this->date($request->query('date')) ?? $today;
        $staffMode = $user->role === Role::Staff;

        if ($staffMode) {
            $employee = $user->employee;
            $position = $user->position;
            $departments = collect();
            $department = $employee->department;
            $positions = collect();
            $employees = collect();
            $checker = null;
            $started = true;
        } else {
            $allowed = $user->accessibleDepartmentIds();
            $departments = Department::where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort')->orderBy('name')->get();
            $department = $departments->firstWhere('id', (int) $request->query('department'))
                ?? $departments->firstWhere('id', $user->employee?->department_id)
                ?? $departments->first();
            $positions = $department
                ? Position::where('department_id', $department->id)->where('is_active', true)
                    ->withCount(['tasks as active_tasks_count' => fn ($q) => $q->where('is_active', true)])
                    ->orderBy('sort')->orderBy('name')->get()->filter(fn (Position $p) => $p->active_tasks_count > 0)->values()
                : collect();
            $employees = $department ? Employee::where('department_id', $department->id)->where('is_active', true)->orderBy('name')->get() : collect();
            $position = $positions->firstWhere('id', (int) $request->query('position'));
            $employee = $employees->firstWhere('id', (int) $request->query('employee'));
            // "Dicek oleh" diingat dari checklist terakhir yang diisi akun ini.
            $checker = $request->query('checker') ?? Checklist::where('filled_by', $user->id)->latest('id')->value('checked_by') ?? $user->name();
            $started = $request->boolean('mulai');
        }

        $mode = null;
        $problem = null;
        $rows = [];
        $checklist = null;
        if ($started && $employee && $position) {
            if ($date->gt($today)) {
                $problem = __('Tanggal tidak boleh di masa depan.');
            } elseif (! Gate::allows('fill', [Checklist::class, $employee, $position])) {
                $problem = $staffMode
                    ? __('Jabatan checklist Anda belum diatur atau tidak sesuai departemen Anda. Hubungi HR.')
                    : __('Anda tidak boleh mengisi checklist ini.');
            } elseif ($checklist = $this->service->find($employee, $position, $date)) {
                $checklist->load(['items', 'extraTasks']);
                $mode = Gate::allows('update', $checklist) ? 'edit' : 'locked';
                $rows = $this->service->rowsForExisting($checklist);
            } else {
                $rows = $this->service->rowsForNew($employee, $position);
                $mode = $rows ? 'new' : 'empty';
            }
        } elseif ($staffMode && ! $position) {
            $problem = __('Jabatan checklist Anda belum diatur, jadi daftar tugas belum bisa dibuat. Hubungi HR atau Administrator.');
        }

        return view('checklists.fill', [
            'staffMode' => $staffMode,
            'departments' => $departments,
            'department' => $department,
            'positions' => $positions,
            'employees' => $employees,
            'employee' => $employee,
            'position' => $position,
            'date' => $date,
            'today' => $today,
            'checker' => $checker,
            'mode' => $mode,
            'problem' => $problem,
            'rows' => $rows,
            'checklist' => $checklist,
            'statuses' => ItemStatus::cases(),
            'history' => $staffMode ? Checklist::where('employee_id', $user->employee_id)->latest('date')->limit(14)->get() : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('fill-checklists');
        $data = $request->validate([
            'position' => ['required', 'integer', 'exists:positions,id'],
            'employee' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], ['date.before_or_equal' => __('Tanggal tidak boleh di masa depan.')]);
        $employee = Employee::findOrFail($data['employee']);
        $position = Position::findOrFail($data['position']);
        Gate::authorize('fill', [Checklist::class, $employee, $position]);

        $input = ChecklistInput::validate($request, $this->service->tasksFor($employee, $position)->pluck('id')->all());
        try {
            $checklist = $this->service->create($employee, $position, CarbonImmutable::parse($data['date']), $request->user(), $input);
        } catch (DuplicateChecklist $duplicate) {
            return redirect()->route('fill.create', $duplicate->existing->fillParams())
                ->withErrors(['duplicate' => __('Checklist ini baru saja disimpan dari tempat lain. Data terbaru dimuat; periksa lalu simpan lagi.')]);
        }

        return redirect()->route('checklists.show', $checklist)->with('status', __('Checklist disimpan.'));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
    }
}

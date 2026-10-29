<?php

namespace App\Http\Controllers;

use App\Checklists\ChecklistService;
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
 * Memulai checklist harian: pilih jabatan, tanggal (boleh mundur, tidak ke depan) dan staff dari data Absensi, lalu "Mulai checklist" membuka
 * halaman kartu / daftar tempat tiap tugas tersimpan otomatis. HOD mengisi untuk staff di departemennya; HR / Administrator untuk semua
 * departemen; staff berakun hanya untuk dirinya sendiri. Memulai staff + jabatan + tanggal yang sudah ada membuka yang lama (tidak ganda).
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
        }

        $problem = $staffMode && ! Gate::allows('fill', [Checklist::class, $employee, $position ?? new Position])
            ? __('Jabatan checklist Anda belum diatur atau tidak sesuai departemen Anda, jadi daftar tugas belum bisa dibuat. Hubungi HR atau Administrator.')
            : null;

        // Checklist yang belum lengkap (tersimpan otomatis tapi belum selesai) supaya mudah dilanjutkan.
        $resume = Checklist::with(['items', 'employee'])->where('status', 'saved')->whereDate('date', '>=', $today->subDays(7))
            ->where(fn ($q) => $q->where('filled_by', $user->id)->orWhere('employee_id', $user->employee_id))
            ->latest('date')->latest('id')->limit(30)->get()->reject(fn (Checklist $c) => $c->isComplete())->take(10);

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
            'problem' => $problem,
            'resume' => $resume,
            'history' => $staffMode ? Checklist::where('employee_id', $user->employee_id)->latest('date')->limit(14)->get() : collect(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        Gate::authorize('fill-checklists');
        $data = $request->validate([
            'position' => ['required', 'integer', 'exists:positions,id'],
            'employee' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'checker' => ['nullable', 'string', 'max:255'],
        ], ['date.before_or_equal' => __('Tanggal tidak boleh di masa depan.')]);
        $employee = Employee::findOrFail($data['employee']);
        $position = Position::findOrFail($data['position']);
        Gate::authorize('fill', [Checklist::class, $employee, $position]);

        $date = CarbonImmutable::parse($data['date']);
        $existing = $this->service->find($employee, $position, $date);
        if ($existing && ! Gate::allows('update', $existing)) {
            return redirect()->route('checklists.show', $existing)->with('status', __('Checklist ini sudah direview dan terkunci. Perubahan hanya lewat HR.'));
        }

        $checklist = $existing ?? $this->service->start($employee, $position, $date, $request->user(), $request->user()->role === Role::Staff ? null : ($data['checker'] ?? null));

        return redirect()->route('checklists.fill', $checklist);
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

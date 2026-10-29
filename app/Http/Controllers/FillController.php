<?php

namespace App\Http\Controllers;

use App\Attendance\AttendanceApiService;
use App\Attendance\AttendanceUnavailable;
use App\Checklists\ChecklistService;
use App\Enums\ChecklistStatus;
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

    public function create(Request $request, AttendanceApiService $attendance): View
    {
        Gate::authorize('fill-checklists');
        $user = $request->user();
        $today = CarbonImmutable::today();
        $date = $this->date($request->query('date')) ?? $today;
        $staffMode = $user->role === Role::Staff;
        $allowedIds = $staffMode ? [] : $user->accessibleDepartmentIds();

        if ($staffMode) {
            $employee = $user->employee;
            $position = $employee->checklistPosition ?? $user->position;
            $departments = collect();
            $department = $employee->department;
            $positions = collect();
            $employees = collect();
        } else {
            $departments = Department::where('is_active', true)->when($allowedIds !== null, fn ($q) => $q->whereIn('id', $allowedIds))->orderBy('sort')->orderBy('name')->get();
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
        }

        $problem = $staffMode && ! Gate::allows('fill', [Checklist::class, $employee, $position ?? new Position])
            ? __('Jabatan checklist Anda belum diatur atau tidak sesuai departemen Anda, jadi daftar tugas belum bisa dibuat. Hubungi HR atau Administrator.')
            : null;

        // Papan tim (HOD / HR / Administrator): setiap staff aktif departemen ini pada tanggal terpilih beserta checklistnya,
        // supaya atasan cukup mengetuk nama staff untuk mulai / melanjutkan.
        // Jadwal Absensi untuk tanggal ini: staff tanpa jadwal (kosong / belum diisi) tidak bisa dibuatkan checklist, jadi ditandai.
        // Gagal membaca Absensi = tidak ada yang ditandai (dan pesannya ditampilkan), supaya tidak salah menonaktifkan staff.
        $schedules = null;
        $scheduleProblem = null;
        if (! $staffMode && $department) {
            try {
                $schedules = $attendance->schedules($date);
            } catch (AttendanceUnavailable $unavailable) {
                $scheduleProblem = $unavailable->getMessage();
            }
        }

        $board = collect();
        if (! $staffMode && $department) {
            $day = Checklist::with('items')->where('department_id', $department->id)->whereDate('date', $date)->get()->groupBy('employee_id');
            $board = $employees->load('checklistPosition')
                ->reject(fn (Employee $e) => $e->id === $user->employee_id && ! $user->isAdmin() && ! $day->has($e->id))
                ->map(function (Employee $e) use ($day, $user, $schedules) {
                    $checklists = $day->get($e->id, collect());
                    $noSchedule = $schedules !== null && ($schedules[$e->external_id]['status'] ?? 'unscheduled') === 'unscheduled';
                    $state = match (true) {
                        $checklists->isEmpty() && $noSchedule => 'noschedule',
                        $checklists->isEmpty() => 'none',
                        $checklists->every(fn (Checklist $c) => $c->isReviewed()) => 'reviewed',
                        $checklists->every(fn (Checklist $c) => $c->status !== ChecklistStatus::Saved) => 'hod_reviewed',
                        $checklists->every(fn (Checklist $c) => $c->isComplete()) => 'complete',
                        $checklists->sum(fn (Checklist $c) => $c->answeredCount()) === 0 => 'empty',
                        default => 'partial',
                    };

                    return [
                        'employee' => $e,
                        'checklists' => $checklists,
                        'state' => $state,
                        'canStart' => $state !== 'noschedule' && $e->checklistPosition && $user->can('fill', [Checklist::class, $e, $e->checklistPosition]),
                    ];
                })->values();
        }

        // Checklist yang belum lengkap (dibuatkan sekaligus atau tersimpan setengah jalan) supaya mudah dilanjutkan:
        // staff melihat miliknya, HOD / HR / Administrator melihat milik departemen yang boleh mereka akses
        // (yang sudah tampil di papan tim, yaitu departemen + tanggal terpilih, tidak diulang).
        $resume = Checklist::with(['items', 'employee'])->where('status', 'saved')->whereDate('date', '>=', $today->subDays(7))
            ->when($staffMode, fn ($q) => $q->where('employee_id', $user->employee_id), fn ($q) => $q->when($allowedIds !== null, fn ($q) => $q->whereIn('department_id', $allowedIds)))
            ->latest('date')->latest('id')->limit(60)->get()->reject(fn (Checklist $c) => $c->isComplete())
            ->reject(fn (Checklist $c) => ! $staffMode && $c->department_id === $department?->id && $c->date->isSameDay($date))->take(30);

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
            'problem' => $problem,
            'resume' => $resume,
            'board' => $board,
            'scheduleProblem' => $scheduleProblem,
            'summary' => session('assign_summary'),
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
        ], ['date.before_or_equal' => __('Tanggal tidak boleh di masa depan.')]);
        $employee = Employee::findOrFail($data['employee']);
        $position = Position::findOrFail($data['position']);
        Gate::authorize('fill', [Checklist::class, $employee, $position]);

        $date = CarbonImmutable::parse($data['date']);
        $existing = $this->service->find($employee, $position, $date);
        if ($existing && ! Gate::allows('update', $existing)) {
            return redirect()->route('checklists.show', $existing)->with('status', __('Checklist ini sudah direview dan terkunci. Perubahan hanya lewat HR.'));
        }

        $checklist = $existing ?? $this->service->start($employee, $position, $date, $request->user(), $request->user()->role === Role::Staff ? null : $request->user()->name());

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

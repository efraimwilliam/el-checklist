<?php

namespace App\Http\Controllers\Api;

use App\Attendance\AttendanceApiService;
use App\Attendance\AttendanceUnavailable;
use App\Checklists\ChecklistService;
use App\Checklists\ChecklistSettings;
use App\Checklists\TeamBoard;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Papan Isi checklist untuk HOD / HR / Administrator di aplikasi Android: sama dengan halaman web `/isi` (TeamBoard).
 * Mulai checklist, buat sekaligus, dan review memakai controller web yang sama (JSON bila lewat /api).
 */
class BoardController extends Controller
{
    public function index(Request $request, AttendanceApiService $attendance, TeamBoard $board, ChecklistService $service): JsonResponse
    {
        Gate::authorize('fill-checklists');
        $user = $request->user();
        abort_if($user->role === Role::Staff, 403);

        $today = CarbonImmutable::today();
        $raw = (string) $request->query('date', '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? CarbonImmutable::parse($raw)->startOfDay() : $today;
        // Sama dengan web: Isi checklist tidak ke depan, kecuali Administrator menyalakan pengisian hari berikutnya.
        if ($date->gt($today) && ! app(ChecklistSettings::class)->futureFillAllowed()) {
            $date = $today;
        }

        $allowed = $user->accessibleDepartmentIds();
        $departments = Department::where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort')->orderBy('name')->get();
        $department = $departments->firstWhere('id', (int) $request->query('department'))
            ?? $departments->firstWhere('id', $user->employee?->department_id)
            ?? $departments->first();

        $schedules = null;
        $scheduleProblem = null;
        if ($department) {
            try {
                $schedules = $attendance->schedules($date);
            } catch (AttendanceUnavailable $unavailable) {
                $scheduleProblem = $unavailable->getMessage();
            }
        }

        $rows = $department
            ? $board->rows($user, Employee::where('department_id', $department->id)->where('is_active', true)->orderBy('name')->get(), $department, $date, $schedules)
            : collect();

        return response()->json([
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'departments' => $departments->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'department_id' => $department?->id,
            'can_assign_all' => Gate::allows('manage-team-tasks'),
            'schedule_problem' => $scheduleProblem,
            'staff' => $rows->map(fn (array $row) => [
                'employee_id' => $row['employee']->id,
                'name' => $row['employee']->name,
                'title' => $row['employee']->position_title,
                'state' => $row['state'],
                'position_id' => $row['employee']->checklistPosition?->id,
                'position' => $row['employee']->checklistPosition?->name,
                'can_start' => (bool) $row['canStart'],
                'checklists' => $row['checklists']->map(fn (Checklist $c) => [
                    'id' => $c->id,
                    'position' => $c->position_name,
                    'progress' => $service->progress($c),
                    'can_edit' => $user->can('update', $c),
                ])->values(),
            ]),
        ]);
    }
}

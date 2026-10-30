<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Checklists\ChecklistService;
use App\Checklists\ChecklistSettings;
use App\Models\Checklist;
use App\Models\Employee;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Hapus rencana checklist untuk hari yang akan datang. Hanya yang belum diisi; yang sudah ada isiannya tetap.
 */
class PlanController extends Controller
{
    public function __construct(private ChecklistService $service) {}

    public function destroy(Checklist $checklist): RedirectResponse
    {
        Gate::authorize('plan', $checklist);
        $checklist->loadMissing('items.parameters', 'items.photos');
        $back = ['date' => $checklist->date->toDateString(), 'department' => $checklist->department_id];

        if (! $checklist->date->isFuture()) {
            return redirect()->route('fill.assign', $back)->withErrors(['plan' => __('Hanya rencana untuk hari yang akan datang yang bisa dihapus.')]);
        }
        if ($this->service->hasAnswers($checklist)) {
            return redirect()->route('fill.assign', $back)->withErrors(['plan' => __('Checklist ini sudah ada isiannya, jadi tidak bisa dihapus.')]);
        }

        $label = $checklist->employee->name.' · '.$checklist->position_name;
        $checklist->delete();
        AuditLogger::record('checklist.hapus_rencana', 'Checklist', $checklist->id, $label);

        return redirect()->route('fill.assign', $back)->with('status', __('Rencana dihapus: :label.', ['label' => $label]));
    }

    /** Mulai checklist untuk tanggal lain: jabatan dan tugas dipilih di popup (tugas bawaan Atur tugas sudah dicentang). */
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee' => ['required', 'integer', 'exists:employees,id'],
            'position' => ['required', 'integer', 'exists:positions,id'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today()->addDays(ChecklistSettings::MAX_DAYS_AHEAD)->toDateString()],
            'tasks' => ['nullable', 'array'],
            'tasks.*' => ['integer'],
        ], ['date.before_or_equal' => __('Tanggal terlalu jauh ke depan (maksimal :n hari).', ['n' => ChecklistSettings::MAX_DAYS_AHEAD])]);

        $employee = Employee::findOrFail($data['employee']);
        $position = Position::findOrFail($data['position']);
        Gate::authorize('fill', [Checklist::class, $employee, $position]);

        $date = CarbonImmutable::parse($data['date']);
        $checklist = $this->service->start($employee, $position, $date, $request->user(), $request->user()->name(), false, array_map('intval', $data['tasks'] ?? []));
        $back = ['date' => $date->toDateString(), 'department' => $position->department_id];

        return redirect()->route('fill.assign', $back)->with('status', __('Checklist :name · :position dibuat.', ['name' => $employee->name, 'position' => $checklist->position_name]));
    }

    public function destroyDay(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'department' => ['required', 'integer', 'exists:departments,id'],
        ]);
        $date = \Carbon\CarbonImmutable::parse($data['date']);
        $back = ['date' => $data['date'], 'department' => $data['department']];

        if (! $date->isFuture()) {
            return redirect()->route('fill.assign', $back)->withErrors(['plan' => __('Hanya rencana untuk hari yang akan datang yang bisa dihapus.')]);
        }

        $deleted = 0;
        $kept = 0;
        Checklist::with('items.parameters', 'items.photos')
            ->where('department_id', $data['department'])->whereDate('date', $date)->get()
            ->each(function (Checklist $checklist) use (&$deleted, &$kept, $request) {
                if ($request->user()->cannot('plan', $checklist) || $this->service->hasAnswers($checklist)) {
                    $kept++;

                    return;
                }
                $checklist->delete();
                $deleted++;
            });

        AuditLogger::record('checklist.hapus_rencana', 'Departemen', (int) $data['department'], $data['date'], ['dihapus' => $deleted, 'tetap' => $kept]);

        return redirect()->route('fill.assign', $back)->with('status', __('Rencana dihapus: :deleted. :kept tetap karena sudah ada isiannya.', ['deleted' => $deleted, 'kept' => $kept]));
    }
}

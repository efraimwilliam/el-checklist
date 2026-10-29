<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Reports\ExcelReport;
use App\Models\Department;
use App\Models\ReportNote;
use App\Reports\ReportService;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Laporan per rentang tanggal, dengan catatan tindak lanjut yang ditambahkan pengguna (HOD / HR / GM / Administrator). */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request): View
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        [$from, $to] = DateRange::fromRequest($request, 1, 92);
        $allowed = $user->accessibleDepartmentIds();

        $notes = ReportNote::with(['department', 'author'])
            ->where('period_from', '<=', $to->toDateString())->where('period_to', '>=', $from->toDateString())
            ->when($allowed !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('department_id')->orWhereIn('department_id', $allowed)))
            ->latest('id')->limit(100)->get();

        $report = $this->reports->build($user, $from, $to);

        return view('laporan.index', $report + [
            'from' => $from,
            'to' => $to,
            'days' => (int) $from->diffInDays($to) + 1,
            'today' => CarbonImmutable::today(),
            'notes' => $notes,
            'noteDepartments' => Department::where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort')->orderBy('name')->get(),
            'canChooseAll' => $allowed === null,
            'exportUrl' => route('laporan.export', ['from' => $from->toDateString(), 'to' => $to->toDateString()]),
            'userId' => $user->id,
            'canOverride' => $user->role->overridesReview(),
        ]);
    }

    /** Ekspor Excel (.xlsx): lembar "Ringkasan" per departemen dan lembar "Checklist" satu baris per checklist dalam rentang. */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        [$from, $to] = DateRange::fromRequest($request, 1, 92);
        $report = $this->reports->build($user, $from, $to);
        $ids = $this->reports->departmentIds($user);

        $checklists = Checklist::with(['employee', 'department', 'items'])
            ->whereIn('department_id', $ids)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->orderBy('department_id')->orderBy('position_name')->orderBy('id')->get();

        return ExcelReport::download("laporan-{$from->toDateString()}-sampai-{$to->toDateString()}.xlsx", [
            [
                'title' => 'Ringkasan',
                'headers' => ['Departemen', 'Checklist', 'Lengkap (%)', __('Pending'), __('Belum direview'), __('Foto kurang'), __('Staff belum punya checklist')],
                'rows' => collect($report['rows'])->map(fn (array $row) => [$row['name'], $row['checklists'], $row['completion'] ?? '', $row['pending'], $row['unreviewed'], $row['photos'], count($row['missing'])])->values()->all(),
            ],
            ExcelReport::checklistSheet($checklists),
        ]);
    }

    public function storeNote(Request $request): RedirectResponse
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], ['body.required' => __('Tulis catatannya dulu.')]);

        $departmentId = $data['department_id'] ?? null;
        if ($departmentId !== null) {
            abort_unless($user->canAccessDepartment($departmentId), 403);
        } elseif (! $user->canAccessDepartment(null) || $user->accessibleDepartmentIds() !== null) {
            return back()->withInput()->withErrors(['department_id' => __('Pilih departemen untuk catatan ini.')]);
        }

        ReportNote::create([
            'department_id' => $departmentId,
            'period_from' => $data['from'],
            'period_to' => $data['to'],
            'body' => trim($data['body']),
            'created_by' => $user->id,
        ]);

        return redirect()->route('laporan.index', ['from' => $data['from'], 'to' => $data['to']])->withFragment('catatan')->with('status', __('Catatan ditambahkan.'));
    }

    public function destroyNote(Request $request, ReportNote $note): RedirectResponse
    {
        Gate::authorize('view-checklists');
        abort_unless($note->created_by === $request->user()->id || $request->user()->role->overridesReview(), 403);
        $note->delete();

        return back()->with('status', __('Catatan dihapus.'));
    }
}

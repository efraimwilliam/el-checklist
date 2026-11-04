<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Reports\ExcelReport;
use App\Models\Department;
use App\Models\IssueFollowup;
use App\Models\ReportNote;
use App\Reports\DataExplorer;
use App\Reports\IssueService;
use App\Reports\MonthlyReport;
use App\Reports\ParameterReport;
use App\Reports\RoomReport;
use App\Models\IssueCategory;
use App\Models\Room;
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
    public function __construct(private ReportService $reports, private IssueService $issues, private DataExplorer $explorer, private MonthlyReport $monthly, private ParameterReport $parameters, private RoomReport $rooms) {}

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

    /** Tab Masalah & Pending: peringkat tugas bermasalah, Pending berulang, peta panas, alasan Pending. */
    public function issues(Request $request): View
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        [$from, $to] = DateRange::fromRequest($request, 14, 92);
        $range = [$from->toDateString(), $to->toDateString()];
        $ids = $this->reports->departmentIds($user);
        $departmentId = $this->departmentFilter($request, $ids);
        $search = trim((string) $request->query('q', '')) ?: null;

        return view('laporan.issues', [
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::today(),
            'departments' => Department::whereIn('id', $ids)->orderBy('sort')->orderBy('name')->get(),
            'departmentId' => $departmentId,
            'search' => $search,
            'open' => $this->issues->open($ids, $range, $departmentId, $request->boolean('all'), search: $search),
            'showAll' => $request->boolean('all'),
            'canFollowUp' => $user->role->managesOthers(),
            'ranked' => $this->issues->ranked($ids, $range, $departmentId, search: $search),
            'chronic' => $this->issues->chronic($ids, $range, $departmentId, $search),
            'heatmap' => $this->issues->heatmap($ids, $range),
            'reasons' => $this->issues->reasons($ids, $range, $departmentId, $search),
            'streak' => IssueService::CHRONIC_STREAK,
            'count' => IssueService::CHRONIC_COUNT,
        ]);
    }

    /** Catat tindak lanjut satu isian Pending (HOD departemennya, HR, Administrator; GM hanya melihat). */
    public function followUp(Request $request, ChecklistItem $item): RedirectResponse
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        abort_unless($user->role->managesOthers() && $user->canAccessDepartment($item->checklist->department_id), 403);
        abort_unless($item->status === \App\Enums\ItemStatus::Pending, 404);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', IssueFollowup::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['status.required' => __('Pilih statusnya.'), 'status.in' => __('Pilih statusnya.')]);

        IssueFollowup::updateOrCreate(['checklist_item_id' => $item->id], [
            'status' => $data['status'],
            'note' => ($note = trim((string) ($data['note'] ?? ''))) !== '' ? $note : null,
            'updated_by' => $user->id,
        ]);

        return back()->withFragment('terbuka')->with('status', __('Tindak lanjut disimpan.'));
    }

    /** Detail satu tugas (departemen + jabatan + judul): tren harian, staff, dan setiap kejadian. */
    public function task(Request $request): View
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        [$from, $to] = DateRange::fromRequest($request, 14, 92);
        $ids = $this->reports->departmentIds($user);
        $departmentId = (int) $request->query('department_id');
        abort_unless(in_array($departmentId, $ids, true), 404);
        $title = (string) $request->query('title');

        return view('laporan.task', [
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::today(),
            'department' => Department::findOrFail($departmentId),
            'title' => $title,
            'detail' => $this->issues->detail($ids, [$from->toDateString(), $to->toDateString()], $departmentId, $title),
            'query' => ['department_id' => $departmentId, 'title' => $title],
        ]);
    }

    /** Tab Data: penjelajah semua isian tugas dengan filter. */
    public function data(Request $request): View
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        [$from, $to] = DateRange::fromRequest($request, 7, 92);
        $range = [$from->toDateString(), $to->toDateString()];
        $ids = $this->reports->departmentIds($user);
        $filters = $this->explorer->filters($request);
        $filters['department_id'] = $this->departmentFilter($request, $ids);
        $rows = $this->explorer->paginate($ids, $range, $filters, max(1, (int) $request->query('page', 1)))
            ->withPath(route('laporan.data'))->appends($request->query());

        return view('laporan.data', [
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::today(),
            'filters' => $filters,
            'rows' => $rows,
            'departments' => Department::whereIn('id', $ids)->orderBy('sort')->orderBy('name')->get(),
            'positions' => $this->explorer->positions($ids, $range, $filters['department_id']),
            'employees' => $this->explorer->employees($ids, $range, $filters['department_id']),
            'exportUrl' => route('laporan.data.export', $request->query()),
        ]);
    }

    /** Excel (.xlsx) semua isian tugas sesuai filter Data (maksimal DataExplorer::EXPORT_LIMIT baris). */
    public function dataExport(Request $request): StreamedResponse
    {
        Gate::authorize('view-checklists');
        [$from, $to] = DateRange::fromRequest($request, 7, 92);
        $ids = $this->reports->departmentIds($request->user());
        $filters = $this->explorer->filters($request);
        $filters['department_id'] = $this->departmentFilter($request, $ids);
        $rows = $this->explorer->all($ids, [$from->toDateString(), $to->toDateString()], $filters);

        return ExcelReport::download("data-tugas-{$from->toDateString()}-sampai-{$to->toDateString()}.xlsx", [ExcelReport::itemSheet($rows)]);
    }

    /** Tab Bulanan: 12 bulan terakhir dari ringkasan harian tersimpan, dan rincian departemen untuk satu bulan. */
    public function monthly(Request $request): View
    {
        Gate::authorize('view-checklists');
        $ids = $this->reports->departmentIds($request->user());
        $departmentId = $this->departmentFilter($request, $ids);
        $months = $this->monthly->months($ids, $departmentId);
        $month = (string) $request->query('bulan');
        if (! in_array($month, array_column($months, 'month'), true)) {
            $month = $months[0]['month'] ?? '';
        }

        return view('laporan.monthly', [
            'from' => CarbonImmutable::today()->subDays(13),
            'to' => CarbonImmutable::today(),
            'departments' => Department::whereIn('id', $ids)->orderBy('sort')->orderBy('name')->get(),
            'departmentId' => $departmentId,
            'months' => $months,
            'month' => $month,
            'byDepartment' => $month !== '' ? $this->monthly->departments($ids, $month) : [],
        ]);
    }

    /** Tab Parameter: nilai angka / stand meter, pembacaan di luar batas, dan konsumsi meter. */
    public function parameters(Request $request): View
    {
        Gate::authorize('view-checklists');
        [$from, $to] = DateRange::fromRequest($request, 14, 92);
        $range = [$from->toDateString(), $to->toDateString()];
        $ids = $this->reports->departmentIds($request->user());
        $departmentId = $this->departmentFilter($request, $ids);

        return view('laporan.parameters', [
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::today(),
            'departments' => Department::whereIn('id', $ids)->orderBy('sort')->orderBy('name')->get(),
            'departmentId' => $departmentId,
            'summary' => $this->parameters->summary($ids, $range, $departmentId),
            'exceptions' => $this->parameters->exceptions($ids, $range, $departmentId),
        ]);
    }

    /** Peta kamar: kendala per kamar (per lantai), kamar langganan masalah, per kategori dan departemen penanggung jawab. */
    public function rooms(Request $request): View
    {
        Gate::authorize('view-checklists');
        [$from, $to] = DateRange::fromRequest($request, 30, 92);
        $range = [$from->toDateString(), $to->toDateString()];
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;
        $categoryId = $request->filled('category_id') ? (int) $request->query('category_id') : null;

        // Denah (bila ada denah aktif, walau kotaknya belum ditandai) menjadi tampilan bawaan; Daftar = kotak per lantai.
        $plans = \App\Models\FloorPlan::where('is_active', true)->with('areas.room')->orderBy('sort')->orderBy('name')->get();
        $mode = $request->query('tampilan') === 'daftar' || $plans->isEmpty() ? 'daftar' : 'denah';
        $plan = $plans->firstWhere('id', (int) $request->query('denah')) ?? $plans->first();

        return view('laporan.rooms', [
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::today(),
            'departmentId' => $departmentId,
            'categoryId' => $categoryId,
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'categories' => IssueCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'report' => $this->rooms->build($request->user(), $range, $departmentId, $categoryId),
            'threshold' => RoomReport::REPEAT_THRESHOLD,
            'plans' => $plans,
            'plan' => $plan,
            'mode' => $mode,
        ]);
    }

    /** Kendala semua kamar di satu gedung / area (dari kotak gedung di peta kawasan). */
    public function building(Request $request): View
    {
        Gate::authorize('view-checklists');
        [$from, $to] = DateRange::fromRequest($request, 90, 92);
        $name = trim((string) $request->query('nama', ''));
        abort_if($name === '', 404);
        $issues = $this->rooms->buildingHistory($request->user(), $name, [$from->toDateString(), $to->toDateString()]);

        return view('laporan.building', [
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::today(),
            'name' => $name,
            'rooms' => Room::where('building', $name)->orderBy('sort')->orderBy('number')->get(),
            'issues' => $issues,
            'byRoom' => $issues->groupBy('room_id')->map->count(),
        ]);
    }

    /** Riwayat kendala satu kamar dalam rentang. */
    public function room(Request $request, Room $room): View
    {
        Gate::authorize('view-checklists');
        [$from, $to] = DateRange::fromRequest($request, 90, 92);

        return view('laporan.room', [
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::today(),
            'room' => $room,
            'issues' => $this->rooms->history($request->user(), $room, [$from->toDateString(), $to->toDateString()]),
        ]);
    }

    /** Filter departemen dari query; hanya yang boleh diakses pengguna (selain itu diabaikan). */
    private function departmentFilter(Request $request, array $ids): ?int
    {
        $id = $request->filled('department_id') ? (int) $request->query('department_id') : null;

        return $id !== null && in_array($id, $ids, true) ? $id : null;
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

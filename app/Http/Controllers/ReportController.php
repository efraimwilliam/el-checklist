<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\Department;
use App\Models\ReportNote;
use App\Reports\ReportService;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Laporan per rentang tanggal, dengan catatan tindak lanjut yang ditambahkan pengguna (HOD / HR / GM / Administrator). */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request): View
    {
        Gate::authorize('view-checklists');
        $user = $request->user();
        [$from, $to] = DateRange::fromRequest($request, 14, 92);
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
        [$from, $to] = DateRange::fromRequest($request, 14, 92);
        $report = $this->reports->build($user, $from, $to);
        $ids = $this->reports->departmentIds($user);

        $checklists = Checklist::with(['employee', 'department', 'items'])
            ->whereIn('department_id', $ids)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->orderBy('department_id')->orderBy('position_name')->orderBy('id')->get();

        $book = new Spreadsheet();
        $summary = $book->getActiveSheet();
        $summary->setTitle('Ringkasan');
        $this->writeTable($summary, ['Departemen', 'Checklist', 'Lengkap (%)', 'Pending', 'Belum direview', 'Foto kurang', 'Staff belum punya checklist'],
            collect($report['rows'])->map(fn (array $row) => [$row['name'], $row['checklists'], $row['completion'] ?? '', $row['pending'], $row['unreviewed'], $row['photos'], count($row['missing'])])->all());

        $detail = $book->createSheet();
        $detail->setTitle('Checklist');
        $this->writeTable($detail, ['Tanggal', 'Departemen', 'Staff', 'Jabatan', 'Skor (%)', 'Done', 'Ongoing', 'Pending', 'Belum diisi', 'Status review', 'Dicek oleh', 'Lengkap'],
            $checklists->map(function (Checklist $checklist) {
                $counts = $checklist->counts();

                return [
                    Date::dateTimeToExcel($checklist->date),
                    $checklist->department->name,
                    $checklist->employee->name,
                    $checklist->position_name,
                    $checklist->score() ?? '',
                    $counts['done'],
                    $counts['ongoing'],
                    $counts['pending'],
                    $checklist->items->whereNull('status')->count(),
                    $checklist->status->label(),
                    $checklist->checked_by ?? 'Diisi sendiri',
                    $checklist->isComplete() ? 'Ya' : 'Belum',
                ];
            })->all(), ['A' => 'dd mmm yyyy']);
        $book->setActiveSheetIndex(0);

        $file = "laporan-{$from->toDateString()}-sampai-{$to->toDateString()}.xlsx";

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Tulis judul + baris ke lembar: judul tebal dengan latar emas, panel beku, filter, lebar kolom menyesuaikan isi.
     *
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @param  array<string, string>  $formats  kolom => format angka
     */
    private function writeTable(Worksheet $sheet, array $headers, array $rows, array $formats = []): void
    {
        $sheet->fromArray([$headers], null, 'A1');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2');
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '2C241E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FBF6EA']],
        ]);
        foreach ($formats as $column => $format) {
            $sheet->getStyle("{$column}2:{$column}".max(2, count($rows) + 1))->getNumberFormat()->setFormatCode($format);
        }
        foreach (range(1, count($headers)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}".max(1, count($rows) + 1));
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

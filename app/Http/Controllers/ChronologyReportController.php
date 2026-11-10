<?php

namespace App\Http\Controllers;

use App\Chronology\ReportParser;
use App\Chronology\ReportText;
use App\Enums\Role;
use App\Models\ChronologyReport;
use App\Models\RoomIssue;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Chronology Report: laporan naratif kejadian dengan tamu (data tamu + poin bernomor + penulis), draft lalu final, siap cetak / salin ke WhatsApp. */
class ChronologyReportController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        Gate::authorize('chronology-reports');
        $user = $request->user();
        $filters = $user->role !== Role::Staff;
        $search = $filters ? (trim((string) $request->query('q', '')) ?: null) : null;
        $status = $filters && in_array($request->query('status'), [ChronologyReport::STATUS_DRAFT, ChronologyReport::STATUS_FINAL], true) ? $request->query('status') : null;

        $reports = ChronologyReport::visibleTo($user)->with(['guests', 'author.employee', 'department'])->withCount('entries')
            ->when($search, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                return $q->whereHas('guests', fn ($g) => $g->where('name', 'like', $like)->orWhere('room_number', 'like', $like));
            })
            ->when($status, fn ($q, $value) => $q->where('status', $value))
            ->orderByDesc('created_at')->paginate(self::PER_PAGE)->withQueryString();

        return view('chronology.index', ['reports' => $reports, 'filters' => $filters, 'search' => $search, 'status' => $status]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('chronology-reports');
        $report = new ChronologyReport(['check_in' => CarbonImmutable::today(), 'status' => ChronologyReport::STATUS_DRAFT]);
        $guests = [['name' => '', 'room_number' => '']];

        // Dari halaman kendala kamar: nomor kamar dan tautan kendala terisi.
        $issue = $request->filled('kendala') ? RoomIssue::with('room')->find((int) $request->query('kendala')) : null;
        if ($issue && $request->user()->can('view', $issue)) {
            $guests = [['name' => '', 'room_number' => $issue->room?->number ?? '']];
            $report->room_issue_id = $issue->id;
        }

        return view('chronology.form', ['report' => $report, 'guests' => $guests, 'entries' => [['body' => '', 'occurred_at' => '']], 'issue' => $issue ?? null, 'pasted' => null, 'parsed' => null, 'warnings' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('chronology-reports');

        return $this->save($request, new ChronologyReport);
    }

    /**
     * Tempel satu kali: teks laporan (dari WhatsApp / Word / email) dibaca otomatis. `mode=fill` mengisi form untuk diperiksa;
     * `mode=save` langsung menyimpannya sebagai draft bila data wajib (nama tamu, tanggal check-in, poin) terbaca lengkap.
     */
    public function paste(Request $request): RedirectResponse|View
    {
        Gate::authorize('chronology-reports');
        $input = $request->validate(['text' => ['required', 'string', 'max:40000'], 'mode' => ['nullable', 'in:fill,save']], ['text.required' => __('Tempel teks laporan dulu.'), 'text.max' => __('Teks terlalu panjang.')]);
        $parsed = ReportParser::parse($input['text']);

        $guests = collect($parsed['guests'])->filter(fn ($g) => $g['name'] !== '')->map(fn ($g) => ['name' => mb_substr($g['name'], 0, 120), 'room_number' => mb_substr($g['room_number'], 0, 30)])->values();
        $entries = collect($parsed['entries'])->map(fn ($body) => ['body' => mb_substr($body, 0, 4000), 'occurred_at' => null])->values();
        $checkIn = $parsed['check_in'];
        $checkOut = $parsed['check_out'] && $checkIn && $parsed['check_out'] >= $checkIn ? $parsed['check_out'] : null;
        $complete = $guests->isNotEmpty() && $entries->isNotEmpty() && $checkIn !== null;

        if (($input['mode'] ?? 'fill') === 'save' && $complete) {
            $report = new ChronologyReport;
            $this->persist($request->user(), $report, [
                'nationality' => $parsed['nationality'] ? mb_substr($parsed['nationality'], 0, 80) : null,
                'check_in' => $checkIn, 'check_out' => $checkOut,
                'booking_source' => $parsed['booking_source'] ? mb_substr($parsed['booking_source'], 0, 120) : null,
            ], $guests, $entries, null);

            return redirect()->route('chronology.show', $report)->with('status', __('Laporan dibaca dan disimpan sebagai draft (:guests tamu, :entries poin). Periksa lalu finalisasi.', ['guests' => $guests->count(), 'entries' => $entries->count()]));
        }

        $report = new ChronologyReport(['nationality' => $parsed['nationality'], 'check_in' => $checkIn ?? CarbonImmutable::today()->toDateString(), 'check_out' => $checkOut, 'booking_source' => $parsed['booking_source'], 'status' => ChronologyReport::STATUS_DRAFT]);

        return view('chronology.form', [
            'report' => $report,
            'guests' => $guests->isNotEmpty() ? $guests->all() : [['name' => '', 'room_number' => '']],
            'entries' => $entries->isNotEmpty() ? $entries->all() : [['body' => '', 'occurred_at' => '']],
            'issue' => null,
            'pasted' => $input['text'],
            'parsed' => $parsed,
            'warnings' => $parsed['warnings'],
        ]);
    }

    public function show(ChronologyReport $report): View
    {
        Gate::authorize('view', $report);
        $report->load(['guests', 'entries', 'author.employee', 'department', 'roomIssue.room']);

        return view('chronology.show', [
            'report' => $report,
            'header' => ReportText::header($report),
            'author' => ReportText::author($report),
            'text' => ReportText::plain($report),
            'statement' => ReportText::STATEMENT,
            'closing' => ReportText::CLOSING,
            'title' => ReportText::TITLE,
        ]);
    }

    public function edit(ChronologyReport $report): View
    {
        Gate::authorize('update', $report);
        $report->load(['guests', 'entries', 'roomIssue.room']);

        return view('chronology.form', [
            'report' => $report,
            'guests' => $report->guests->map(fn ($g) => ['name' => $g->name, 'room_number' => $g->room_number])->all(),
            'entries' => $report->entries->map(fn ($e) => ['body' => $e->body, 'occurred_at' => $e->occurred_at?->format('Y-m-d\TH:i')])->all(),
            'issue' => $report->roomIssue,
            'pasted' => null, 'parsed' => null, 'warnings' => [],
        ]);
    }

    public function update(Request $request, ChronologyReport $report): RedirectResponse
    {
        Gate::authorize('update', $report);

        return $this->save($request, $report);
    }

    public function finalize(ChronologyReport $report): RedirectResponse
    {
        Gate::authorize('update', $report);
        $report->update(['status' => ChronologyReport::STATUS_FINAL, 'finalized_at' => now()]);

        return redirect()->route('chronology.show', $report)->with('status', __('Laporan difinalisasi dan dikunci.'));
    }

    public function reopen(ChronologyReport $report): RedirectResponse
    {
        Gate::authorize('reopen', $report);
        $report->update(['status' => ChronologyReport::STATUS_DRAFT, 'finalized_at' => null]);

        return redirect()->route('chronology.edit', $report)->with('status', __('Laporan dibuka kembali sebagai draft.'));
    }

    public function destroy(ChronologyReport $report): RedirectResponse
    {
        Gate::authorize('delete', $report);
        $report->delete();

        return redirect()->route('chronology.index')->with('status', __('Laporan dihapus.'));
    }

    /** Simpan laporan baru atau yang sudah ada (tamu dan poin diganti seluruhnya sesuai urutan di form). `intent=final` sekaligus mengunci. */
    private function save(Request $request, ChronologyReport $report): RedirectResponse
    {
        $data = $request->validate([
            'nationality' => ['nullable', 'string', 'max:80'],
            'check_in' => ['required', 'date'],
            'check_out' => ['nullable', 'date', 'after_or_equal:check_in'],
            'booking_source' => ['nullable', 'string', 'max:120'],
            'room_issue_id' => ['nullable', 'integer', 'exists:room_issues,id'],
            'guests' => ['required', 'array', 'max:10'],
            'guests.*.name' => ['nullable', 'string', 'max:120'],
            'guests.*.room_number' => ['nullable', 'string', 'max:30'],
            'entries' => ['required', 'array', 'max:40'],
            'entries.*.body' => ['nullable', 'string', 'max:4000'],
            'entries.*.occurred_at' => ['nullable', 'date'],
            'intent' => ['nullable', 'in:draft,final'],
        ], [
            'check_in.required' => __('Isi tanggal check-in.'),
            'check_out.after_or_equal' => __('Tanggal check-out tidak boleh sebelum check-in.'),
            'entries.*.body.max' => __('Satu poin maksimal 4000 karakter.'),
        ]);

        $guests = collect($data['guests'])->filter(fn ($g) => filled($g['name'] ?? null))->values();
        $entries = collect($data['entries'])->filter(fn ($e) => filled($e['body'] ?? null))->values();
        if ($guests->isEmpty()) {
            throw ValidationException::withMessages(['guests' => __('Isi minimal satu nama tamu.')]);
        }
        if ($entries->isEmpty()) {
            throw ValidationException::withMessages(['entries' => __('Tulis minimal satu poin kronologi.')]);
        }

        $issueId = null;
        if (filled($data['room_issue_id'] ?? null)) {
            $issue = RoomIssue::find($data['room_issue_id']);
            $issueId = $issue && $request->user()->can('view', $issue) ? $issue->id : null;
        }

        $this->persist($request->user(), $report, $data, $guests, $entries, $issueId, ($data['intent'] ?? 'draft') === 'final');

        return redirect()->route('chronology.show', $report)->with('status', $report->isFinal() ? __('Laporan difinalisasi dan dikunci.') : __('Draft disimpan.'));
    }

    /** Menyimpan laporan beserta tamu dan poinnya (urutan = urutan di daftar); `$final` sekaligus mengunci. */
    private function persist(\App\Models\User $user, ChronologyReport $report, array $data, Collection $guests, Collection $entries, ?int $issueId, bool $final = false): void
    {
        DB::transaction(function () use ($user, $report, $data, $guests, $entries, $issueId, $final) {
            $report->fill([
                'nationality' => filled($data['nationality'] ?? null) ? trim($data['nationality']) : null,
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'] ?? null,
                'booking_source' => filled($data['booking_source'] ?? null) ? trim($data['booking_source']) : null,
                'room_issue_id' => $issueId,
            ]);
            if (! $report->exists) {
                $report->fill(['author_id' => $user->id, 'department_id' => $user->checklistDepartmentId(), 'status' => ChronologyReport::STATUS_DRAFT]);
            }
            $report->save();

            $report->guests()->delete();
            foreach ($guests as $index => $guest) {
                $report->guests()->create(['sort' => $index + 1, 'name' => trim($guest['name']), 'room_number' => filled($guest['room_number'] ?? null) ? trim($guest['room_number']) : null]);
            }
            $report->entries()->delete();
            foreach ($entries as $index => $entry) {
                $report->entries()->create(['sort' => $index + 1, 'occurred_at' => filled($entry['occurred_at'] ?? null) ? $entry['occurred_at'] : null, 'body' => trim($entry['body'])]);
            }
            if ($final) {
                $report->update(['status' => ChronologyReport::STATUS_FINAL, 'finalized_at' => now()]);
            }
        });
    }
}
